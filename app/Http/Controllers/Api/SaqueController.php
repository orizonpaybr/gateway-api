<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Helpers\TaxaSaqueHelper;
use App\Http\Controllers\Controller;
use App\Jobs\ClientWebhookDispatchJob;
use App\Models\Adquirente;
use App\Models\App;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Services\ClientWebhookPayloadBuilder;
use App\Services\PixAcquirer\PixAcquirerManager;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SaqueController extends Controller
{
    public function makePayment(Request $request)
    {
        // Verificar se o usuário está autenticado
        $user = $request->user();
        Log::info('SaqueController - Verificação de usuário', [
            'user_from_request' => $user ? 'Presente' : 'Ausente',
            'user_id' => $user ? $user->id : 'N/A',
            'request_data' => $request->all(),
        ]);

        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Usuário não autenticado'], 401);
        }

        Helper::calculaSaldoLiquido($user->user_id);

        // Cache para configurações do app (TTL: 5 minutos)
        $setting = Cache::remember('app_settings', 300, function () {
            return App::first();
        });

        if (! $setting) {
            return response()->json(['status' => 'error', 'message' => 'Configurações do aplicativo não encontradas.'], 500);
        }

        // Cache para adquirente padrão do usuário (TTL: 10 minutos)
        $cacheKey = "user_default_acquirer_{$user->user_id}";
        $default = Cache::remember($cacheKey, 600, function () use ($user) {
            return Helper::adquirenteDefault($user->user_id);
        });

        if (! $default) {
            return response()->json(['status' => 'error', 'message' => 'Nenhum adquirente configurado.'], 500);
        }

        // Verificar se o saque está bloqueado para este usuário (sem query adicional)
        if ($user->saque_bloqueado ?? false) {
            Log::warning('Tentativa de saque bloqueado', [
                'user_id' => $user->id,
                'username' => $user->username,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Saque bloqueado para este usuário. Entre em contato com o suporte.',
            ], 403);
        }

        // Determinar se é saque via interface web ou API
        $isInterfaceWeb = $request->input('baasPostbackUrl') === 'web';

        // Debug: Log da requisição
        Log::info('[IP_CHECK] Debug da requisição', [
            'user_id' => $user->user_id,
            'baasPostbackUrl' => $request->input('baasPostbackUrl'),
            'is_interface_web' => $isInterfaceWeb,
        ]);

        // Nota: A verificação de IP é feita pelo middleware CheckAllowedIP

        // Verificar saldo disponível = saldo principal + saldo de afiliados (considerando valores em mediação)
        $saldoDisponivel = (float) ($user->saldo ?? 0) + (float) ($user->saldo_afiliado ?? 0);

        // Calcular valores bloqueados em mediação
        $valoresEmMediacao = \App\Models\Solicitacoes::where('user_id', $user->id)
            ->where('status', 'MEDIATION')
            ->sum('deposito_liquido');

        $saldoRealDisponivel = $saldoDisponivel - (float) $valoresEmMediacao;

        if ($saldoRealDisponivel < (float) $request->amount) {
            $this->dispatchWebhookFalhaSaldoCoratri(
                $request,
                $user,
                (float) $request->amount,
                $saldoRealDisponivel
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Não foi possível sacar, entre em contato com o suporte.',
            ], 401);
        }

        try {
            $validated = $request->validate([
                'token' => ['required', 'string'],
                'secret' => ['required', 'string'],
                'amount' => ['required'],
                'pixKey' => ['required', 'string'],
                'pixKeyType' => ['required', 'string', 'in:cpf,cnpj,email,telefone,phone,aleatoria,random,crypto'],
                'baasPostbackUrl' => ['required', 'string'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erro de validação',
                'errors' => $e->errors(),
            ], 422); // Status code 422 para erros de validação
        }

        // Limite máximo por saque PIX (ex.: R$ 100.000,00)
        $limiteMaximoSaque = (float) config('saque.limite_maximo_por_saque', 100000);
        if ((float) $request->amount > $limiteMaximoSaque) {
            return response()->json([
                'status' => 'error',
                'message' => 'Valor acima do limite máximo por saque de R$ '.number_format($limiteMaximoSaque, 2, ',', '.').'.',
            ], 422);
        }

        $processarAutomatico = \App\Helpers\WithdrawalConfigResolver::isAutomatico($user, $setting, (float) $request->amount);

        if ($processarAutomatico) {
            return $this->processarSaqueAutomatico($request, $default, $setting, $isInterfaceWeb);
        }

        return $this->processarSaqueManual($request, $default, $setting, $isInterfaceWeb);
    }

    /**
     * Processa saque automático - executa o pagamento diretamente
     */
    private function processarSaqueAutomatico(Request $request, $default, $setting, $isInterfaceWeb = false)
    {
        $request->merge(['saque_automatico' => true]);

        return $this->processarSaque($request, $default, true, $setting, $isInterfaceWeb);
    }

    /**
     * Processa saque manual - cria solicitação para aprovação
     */
    private function processarSaqueManual(Request $request, $default, $setting, $isInterfaceWeb = false)
    {
        return $this->processarSaque($request, $default, false, $setting, $isInterfaceWeb);
    }

    /**
     * Processa saque
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function processarSaque(Request $request, string $default, bool $isAutomatico, $setting, bool $isInterfaceWeb = false)
    {
        /** @var SolicitacoesCashOut|null Saque automático criado em PENDING antes do Pix Out na adquirente (correlação webhook). */
        $provisionedAutoWithdrawal = null;

        /** @var bool True após a adquirente aceitar o Pix Out (evita marcar FAILED no catch se o webhook ainda precisa reconciliar). */
        $pixOutApiAccepted = false;

        try {
            if (strtolower($default) === 'pagarme') {
                return response()->json(['status' => 'error', 'message' => 'Adquirente não suportado para saques.'], 500);
            }

            $user = $request->user();
            if (! $user || ! $setting) {
                return response()->json(['status' => 'error', 'message' => 'Dados inválidos para saque.'], 500);
            }

            $amount = (float) $request->amount;
            $taxaCalculada = TaxaSaqueHelper::calcularTaxaSaque($amount, $setting, $user, $isInterfaceWeb, false, $default);
            $taxaCashOut = $taxaCalculada['taxa_cash_out'];
            $taxaAplicacao = $taxaCalculada['taxa_aplicacao'];
            $taxaAdquirente = $taxaCalculada['taxa_adquirente'];
            $cashOutLiquido = $taxaCalculada['saque_liquido'];
            $valorTotalDescontar = $taxaCalculada['valor_total_descontar'];

            $clientPostbackUrl = ($request->filled('baasPostbackUrl') && $request->baasPostbackUrl !== 'web')
                ? trim((string) $request->baasPostbackUrl)
                : null;
            if ($clientPostbackUrl === '') {
                $clientPostbackUrl = null;
            }

            $keyValue = $request->pixKey;
            $keyType = strtolower((string) $request->pixKeyType);
            if ($keyType === 'phone') {
                $keyType = 'telefone';
                $keyValue = preg_replace('/\D/', '', (string) $keyValue);
            }

            $balanceService = app(\App\Services\BalanceService::class);
            $saldoTotalDisponivel = $balanceService->getTotalAvailableBalance($user);
            if ($saldoTotalDisponivel < $valorTotalDescontar) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Não foi possível sacar, entre em contato com o suporte.',
                ], 400);
            }

            if (! $isAutomatico) {
                $idempotencyKey = uniqid('withdraw_api_manual_', true);
                $description = $request->description ?? 'Saque via API PIX';

                $withdrawal = DB::transaction(function () use (
                    $user,
                    $idempotencyKey,
                    $amount,
                    $keyValue,
                    $keyType,
                    $taxaCashOut,
                    $cashOutLiquido,
                    $valorTotalDescontar,
                    $clientPostbackUrl
                ) {
                    $w = SolicitacoesCashOut::create([
                        'user_id' => $user->user_id ?? $user->username,
                        'externalreference' => $idempotencyKey,
                        'amount' => $amount,
                        'beneficiaryname' => $user->name ?? 'Não informado',
                        'beneficiarydocument' => $user->cpf_cnpj ?? '',
                        'pix' => $keyValue,
                        'pixkey' => $keyType,
                        'idTransaction' => $idempotencyKey,
                        'status' => 'PENDING',
                        'type' => 'PIX',
                        'date' => now(),
                        'taxa_cash_out' => $taxaCashOut,
                        'valor_total_descontado' => round($valorTotalDescontar, 4),
                        'cash_out_liquido' => $cashOutLiquido,
                        'descricao_transacao' => 'MANUAL',
                        'executor_ordem' => null,
                        'callback' => $clientPostbackUrl,
                    ]);

                    $balanceService = app(\App\Services\BalanceService::class);
                    $balanceService->decrementCombinedBalance($user, $valorTotalDescontar);

                    return $w;
                });

                Helper::calculaSaldoLiquido($user->user_id ?? $user->username);
                app(\App\Services\PaymentProcessingService::class)->invalidateCachesAfterPayment($withdrawal->user_id);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Saque criado com sucesso e aguardando aprovação manual.',
                    'data' => [
                        'transaction_id' => $idempotencyKey,
                        'withdrawal_id' => $withdrawal->id,
                        'amount' => $amount,
                        'pixKeyType' => $keyType,
                        'pixKey' => $keyValue,
                        'description' => $description,
                        'status' => 'PENDING_APPROVAL',
                        'tipo_processamento' => 'Manual',
                        'created_at' => now()->toISOString(),
                        'taxa_cash_out' => round($taxaCashOut, 2),
                        'taxa_adquirente' => round($taxaAdquirente, 2),
                        'taxa_aplicacao' => round($taxaAplicacao, 2),
                        'valor_liquido' => round($cashOutLiquido, 2),
                        'valor_total_descontar' => round($valorTotalDescontar, 2),
                    ],
                ], 200);
            }

            $acquirerService = app(PixAcquirerManager::class)->resolve($default);
            if (! $acquirerService->isActive()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'PIX temporariamente indisponível.',
                ], 503);
            }

            $correlationID = preg_replace('/[^a-zA-Z0-9]/', '', Str::uuid()->toString());
            $description = $request->description ?? 'Saque via API PIX';
            $recipientName = $user->name ?? 'Não informado';
            $recipientDocument = in_array($keyType, ['cpf', 'cnpj'], true)
                ? preg_replace('/\D/', '', (string) $keyValue)
                : null;
            if ($recipientDocument === '') {
                $recipientDocument = null;
            }

            // Persistir antes do Pix Out: se a adquirente aceitar e o PHP falhar depois, o webhook ainda encontra por externalId/correlationId.
            $withdrawal = SolicitacoesCashOut::create([
                'user_id' => $user->user_id ?? $user->username,
                'externalreference' => $correlationID,
                'amount' => $amount,
                'beneficiaryname' => $user->name ?? 'Não informado',
                'beneficiarydocument' => $user->cpf_cnpj ?? '',
                'pix' => $keyValue,
                'pixkey' => $keyType,
                'idTransaction' => $correlationID,
                'status' => 'PENDING',
                'type' => 'PIX',
                'date' => now(),
                'taxa_cash_out' => $taxaCashOut,
                'valor_total_descontado' => round($valorTotalDescontar, 4),
                'cash_out_liquido' => $cashOutLiquido,
                'descricao_transacao' => 'AUTOMATICO',
                'executor_ordem' => $acquirerService->getReference(),
                'descricao_externa' => $correlationID,
                'callback' => $clientPostbackUrl,
            ]);
            $provisionedAutoWithdrawal = $withdrawal;

            $payoutResult = $acquirerService->createPayout(
                $amount,
                $keyValue,
                $keyType,
                $description,
                $correlationID,
                $recipientName,
                $recipientDocument
            );

            if (! ($payoutResult['success'] ?? false)) {
                $withdrawal->update(['status' => 'FAILED']);
                $provisionedAutoWithdrawal = null;

                Log::error('SaqueController::processarSaque — adquirente recusou payout', [
                    'acquirer' => $acquirerService->getReference(),
                    'message' => $payoutResult['message'] ?? 'N/A',
                    'user_id' => $user->username,
                    'amount' => $amount,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => $payoutResult['message'] ?? 'Não foi possível processar o saque PIX.',
                ], 400);
            }

            $pixOutApiAccepted = true;

            $idTxnRaw = $payoutResult['referenceCode'] ?? $correlationID;
            $idTxn = is_string($idTxnRaw) ? trim($idTxnRaw) : (string) $idTxnRaw;
            if ($idTxn === '') {
                $idTxn = $correlationID;
            }
            $statusMapped = $acquirerService->mapPayoutStatus((string) ($payoutResult['status'] ?? 'pending'));

            $raw = $payoutResult['raw'] ?? [];
            $e2e = null;
            if (is_array($raw)) {
                $e = $raw['endToEndId'] ?? $raw['endToEndid'] ?? null;
                $e2e = is_string($e) && $e !== '' ? $e : null;
            }

            DB::transaction(function () use ($withdrawal, $user, $balanceService, $idTxn, $statusMapped, $e2e, $valorTotalDescontar) {
                $w = SolicitacoesCashOut::where('id', $withdrawal->id)->lockForUpdate()->first();
                if ($w === null) {
                    return;
                }
                $w->update([
                    'idTransaction' => $idTxn,
                    'externalreference' => $idTxn,
                    'end_to_end' => $e2e,
                    'status' => $statusMapped,
                ]);
                $balanceService->decrementCombinedBalance($user, $valorTotalDescontar);
            });

            $withdrawal->refresh();
            $provisionedAutoWithdrawal = null;

            Helper::calculaSaldoLiquido($user->user_id ?? $user->username);
            app(\App\Services\PaymentProcessingService::class)->invalidateCachesAfterPayment($withdrawal->user_id);

            return response()->json([
                'status' => 'success',
                'message' => 'Saque PIX processado.',
                'data' => [
                    'transaction_id' => $idTxn,
                    'amount' => $amount,
                    'pixKeyType' => $keyType,
                    'pixKey' => $keyValue,
                    'description' => $description,
                    'status' => $statusMapped,
                    'tipo_processamento' => 'Automático',
                    'created_at' => now()->toISOString(),
                    'adquirente' => $acquirerService->getReference(),
                    'taxa_cash_out' => round($taxaCashOut, 2),
                    'taxa_adquirente' => round($taxaAdquirente, 2),
                    'taxa_aplicacao' => round($taxaAplicacao, 2),
                    'valor_liquido' => round($cashOutLiquido, 2),
                    'valor_total_descontado' => round($valorTotalDescontar, 2),
                ],
            ], 200);
        } catch (\Exception $e) {
            if ($provisionedAutoWithdrawal instanceof SolicitacoesCashOut && ! $pixOutApiAccepted) {
                try {
                    $provisionedAutoWithdrawal->refresh();
                    if ($provisionedAutoWithdrawal->status === 'PENDING') {
                        $provisionedAutoWithdrawal->update(['status' => 'FAILED']);
                    }
                } catch (\Throwable) {
                    // evitar mascarar o erro original
                }
            }

            $tipo = $isAutomatico ? 'automático' : 'manual';
            Log::error("Erro no saque {$tipo}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'adquirente' => $default,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => "Erro ao processar saque {$tipo}. Tente novamente.",
            ], 500);
        }
    }

    /**
     * Dispara webhook de falha por saldo insuficiente na conta Coratri do usuário.
     * Só usa saldo da Coratri (conta do usuário); nunca expõe dados do AdquirentePIX/conta master.
     * Só é chamado quando a falha é por saldo Coratri; em falhas do AdquirentePIX mantemos mensagem genérica.
     */
    private function dispatchWebhookFalhaSaldoCoratri(Request $request, User $user, float $amountRequested, float $saldoCoratriDisponivel): void
    {
        $callbackUrl = $request->filled('baasPostbackUrl') && $request->baasPostbackUrl !== 'web'
            ? $request->baasPostbackUrl
            : null;
        if (! $callbackUrl) {
            return;
        }

        $idTransaction = 'PAYOUT_API_'.preg_replace('/[^a-zA-Z0-9]/', '', Str::uuid()->toString());
        $messageWebhook = 'Saldo insuficiente. Você tentou sacar R$ '.number_format($amountRequested, 2, ',', '.')
            .', seu saldo disponível é R$ '.number_format($saldoCoratriDisponivel, 2, ',', '.').'.';

        try {
            $row = SolicitacoesCashOut::create([
                'user_id' => $user->username,
                'externalreference' => $idTransaction,
                'amount' => $amountRequested,
                'beneficiaryname' => $request->input('beneficiary_name') ?? $user->name ?? $user->username ?? 'N/A',
                'beneficiarydocument' => $request->input('pixKey', ''),
                'pix' => $request->input('pixKey', ''),
                'pixkey' => $request->input('pixKeyType', 'cpf'),
                'date' => Carbon::now(),
                'status' => 'FAILED',
                'type' => 'PIX',
                'idTransaction' => $idTransaction,
                'taxa_cash_out' => 0,
                'cash_out_liquido' => $amountRequested,
                'callback' => $callbackUrl,
            ]);

            ClientWebhookDispatchJob::dispatch(
                $callbackUrl,
                $idTransaction,
                'FAILED',
                $amountRequested,
                now()->toIso8601String(),
                ClientWebhookPayloadBuilder::extraForCashOut($row),
                $messageWebhook
            );
        } catch (\Throwable $e) {
            Log::warning('SaqueController::dispatchWebhookFalhaSaldoCoratri - Erro ao criar registro ou disparar webhook', [
                'error' => $e->getMessage(),
                'user_id' => $user->username,
            ]);
        }
    }
}
