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
use App\Services\CashOut\CashOutOutcomeApplier;
use App\Services\ClientWebhookPayloadBuilder;
use App\Services\FluxPayments\FluxPaymentsCashOutOutcomeService;
use App\Services\FluxPayments\FluxPaymentsPixAcquirerService;
use App\Services\Fyhub\FyhubCashOutOutcomeService;
use App\Services\Treeal\TreealCashOutOutcomeService;
use App\Services\PixAcquirer\PixAcquirerManager;
use App\Services\WithdrawalFailureRefundService;
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
            Log::channel('security')->warning('Tentativa de saque bloqueado', [
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

        // Saldo disponível para saque = saldo principal + afiliado - depósitos em mediação (MED).
        // Centralizado no BalanceService para que TODOS os fluxos de saque (pixout, web,
        // pagarme) apliquem o mesmo bloqueio de valores sob infração.
        $saldoRealDisponivel = app(\App\Services\BalanceService::class)->getTotalAvailableBalance($user);

        $amountSolicitado = (float) $request->amount;
        $taxaPreview = TaxaSaqueHelper::calcularTaxaSaque($amountSolicitado, $setting, $user, $isInterfaceWeb, false, $default);
        $valorTotalNecessario = (float) $taxaPreview['valor_total_descontar'];

        // Taxa por dentro: o cliente recebe (valor - taxa). O valor precisa ser maior que a taxa.
        if ((float) $taxaPreview['saque_liquido'] < 0.01) {
            return response()->json([
                'status' => 'error',
                'message' => 'O valor do saque precisa ser maior que a taxa.',
            ], 422);
        }

        if ($saldoRealDisponivel < $valorTotalNecessario) {
            $this->registrarFalhaSaldoCoratri(
                $request,
                $user,
                $amountSolicitado,
                $saldoRealDisponivel,
                $valorTotalNecessario,
                (float) ($taxaPreview['taxa_cash_out'] ?? 0),
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Não foi possível sacar, entre em contato com o suporte.',
            ], 400);
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

            // Taxa por dentro: o valor precisa ser maior que a taxa (líquido a pagar ao cliente).
            if ((float) $cashOutLiquido < 0.01) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'O valor do saque precisa ser maior que a taxa.',
                ], 422);
            }

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
                $this->registrarFalhaSaldoCoratri(
                    $request,
                    $user,
                    $amount,
                    $saldoTotalDisponivel,
                    $valorTotalDescontar,
                    (float) $taxaCashOut,
                );

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
                    $clientPostbackUrl,
                    $default
                ) {
                    $w = SolicitacoesCashOut::create([
                        'user_id' => $user->user_id ?? $user->username,
                        'externalreference' => $idempotencyKey,
                        'amount' => $amount,
                        'beneficiaryname' => '',
                        'beneficiarydocument' => '',
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
                        'executor_ordem' => $default,
                        'adquirente_ref' => $default,
                        'callback' => $clientPostbackUrl,
                    ]);

                    $balanceService = app(\App\Services\BalanceService::class);
                    $dec = $balanceService->decrementCombinedBalanceWithSplit($user, $valorTotalDescontar, [
                        'reason' => 'withdrawal_debit',
                        'source' => 'SaqueController::manual',
                        'ref_type' => 'solicitacoes_cash_out',
                        'ref_id' => $w->id,
                    ]);
                    $w->update([
                        'debito_saldo_afiliado' => $dec['debito_saldo_afiliado'],
                        'debito_saldo_principal' => $dec['debito_saldo_principal'],
                    ]);

                    return $w->fresh();
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
                : preg_replace('/\D/', '', (string) ($user->cpf_cnpj ?? ''));
            if ($recipientDocument === '') {
                $recipientDocument = null;
            }

            // Reserva (linha + débito) ANTES do payout: nunca enviar PIX sem saldo já debitado.
            // Debitar depois permitia o débito estourar "saldo insuficiente" com o PIX já pago
            // (rollback desfazia o débito, a linha sobrevivia sem debito_* e o webhook virava COMPLETED).
            // O lock do usuário serializa saques concorrentes: um Pix Out por cliente por vez.
            // Retorna null quando já há um saque em voo → duplicidade, respondida com 409.
            $withdrawal = DB::transaction(function () use (
                $user,
                $correlationID,
                $amount,
                $keyValue,
                $keyType,
                $taxaCashOut,
                $cashOutLiquido,
                $valorTotalDescontar,
                $clientPostbackUrl,
                $acquirerService,
                $balanceService,
                $default
            ) {
                // Lock do usuário PRIMEIRO: serializa dois pedidos simultâneos do mesmo cliente.
                $locked = User::where('id', $user->id)->lockForUpdate()->first();

                // Um saque por vez: se já houver um em voo, não dispara outro Pix Out.
                if (SolicitacoesCashOut::userHasInFlightWithdrawal($locked)) {
                    return null;
                }

                $w = SolicitacoesCashOut::create([
                    'user_id' => $locked->user_id ?? $locked->username,
                    'externalreference' => $correlationID,
                    'amount' => $amount,
                    'beneficiaryname' => '',
                    'beneficiarydocument' => '',
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
                    'adquirente_ref' => $default,
                    'descricao_externa' => $correlationID,
                    'callback' => $clientPostbackUrl,
                ]);

                $dec = $balanceService->decrementCombinedBalanceWithSplit($locked, $valorTotalDescontar, [
                    'reason' => 'withdrawal_debit',
                    'source' => 'SaqueController::automatico',
                    'ref_type' => 'solicitacoes_cash_out',
                    'ref_id' => $w->id,
                ]);
                $w->update([
                    'debito_saldo_afiliado' => $dec['debito_saldo_afiliado'],
                    'debito_saldo_principal' => $dec['debito_saldo_principal'],
                ]);

                return $w->fresh();
            });

            if ($withdrawal === null) {
                Log::warning('[PIXOUT] Saque bloqueado: já há um em andamento para o cliente', [
                    'user_id' => $user->username,
                    'amount' => $amount,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Você já tem um saque em andamento. Aguarde a conclusão antes de solicitar outro.',
                ], 409);
            }

            $payoutResult = $acquirerService->createPayout(
                $cashOutLiquido,
                $keyValue,
                $keyType,
                $description,
                $correlationID,
                $recipientName,
                $recipientDocument
            );

            if (! ($payoutResult['success'] ?? false)) {
                $indeterminado = (bool) ($payoutResult['indeterminate'] ?? false);

                Log::error('SaqueController::processarSaque — adquirente recusou payout', [
                    'acquirer' => $acquirerService->getReference(),
                    'message' => $payoutResult['message'] ?? 'N/A',
                    'user_id' => $user->username,
                    'amount' => $amount,
                    'cash_out_id' => $withdrawal->id,
                    'indeterminate' => $indeterminado,
                ]);

                if ($indeterminado) {
                    // Timeout/rede: o PIX pode ter saído. Estornar aqui devolveria saldo de
                    // um PIX pago. Fica debitado em PROCESSING para o webhook/poll resolver.
                    Log::critical('[PIXOUT] Resultado INDETERMINADO — saldo mantido debitado, exige conciliação', [
                        'cash_out_id' => $withdrawal->id,
                        'correlation_id' => $correlationID,
                        'acquirer' => $acquirerService->getReference(),
                        'user_id' => $user->username,
                        'valor_total_descontado' => $valorTotalDescontar,
                    ]);
                    $withdrawal->update(['status' => 'PROCESSING']);

                    return response()->json([
                        'status' => 'error',
                        'message' => 'Saque em processamento. Aguarde a confirmação.',
                    ], 202);
                }

                // Recusa definitiva: nada saiu do banco, devolver o valor reservado.
                $withdrawal->update(['status' => 'FAILED']);
                WithdrawalFailureRefundService::creditBackIfApplicable($withdrawal->fresh(), 'PENDING', 'FAILED');

                return response()->json([
                    'status' => 'error',
                    'message' => $payoutResult['message'] ?? 'Não foi possível processar o saque PIX.',
                ], 400);
            }

            $idTxnRaw = $payoutResult['referenceCode'] ?? $correlationID;
            $idTxn = is_string($idTxnRaw) ? trim($idTxnRaw) : (string) $idTxnRaw;
            if ($idTxn === '') {
                $idTxn = $correlationID;
            }
            $raw = $payoutResult['raw'] ?? [];
            $e2e = null;
            if (is_array($raw)) {
                $e = $raw['endToEndId'] ?? $raw['endToEndid'] ?? null;
                $e2e = is_string($e) && $e !== '' ? $e : null;
            }

            $providerStatus = (string) ($payoutResult['status'] ?? 'pending');
            $statusMapped = $acquirerService instanceof \App\Services\Fyhub\FyhubPixAcquirerService
                || $acquirerService instanceof \App\Services\Treeal\TreealPixAcquirerService
                ? $acquirerService->resolveInitialPayoutStatus($providerStatus, $e2e)
                : $acquirerService->mapPayoutStatus($providerStatus);

            // Status terminal na resposta síncrona: gravar PROCESSING e aplicar via OutcomeApplier
            // (postback baasPostbackUrl + comissão). Gravar COMPLETED direto impede o applier e o poll.
            $statusForDb = CashOutOutcomeApplier::isTerminalStatus($statusMapped)
                ? 'PROCESSING'
                : $statusMapped;

            // Linha e débito já existem (reserva acima): só correlacionar com a adquirente.
            $withdrawal->update([
                'idTransaction' => $idTxn,
                'status' => $statusForDb,
                'end_to_end' => $e2e,
            ]);
            $withdrawal->refresh();

            if (CashOutOutcomeApplier::isTerminalStatus($statusMapped)) {
                if ($acquirerService->getReference() === 'fyhub') {
                    app(FyhubCashOutOutcomeService::class)->applySyncTerminalOutcome(
                        $withdrawal,
                        $statusMapped,
                        is_array($raw) ? $raw : [],
                        $e2e,
                    );
                } elseif ($acquirerService->getReference() === 'treeal') {
                    app(TreealCashOutOutcomeService::class)->applySyncTerminalOutcome(
                        $withdrawal,
                        $statusMapped,
                        is_array($raw) ? $raw : [],
                        $e2e,
                    );
                } else {
                    app(CashOutOutcomeApplier::class)->applyTerminalStatusIfNeeded(
                        $withdrawal,
                        $statusMapped,
                        is_array($raw) ? $raw : [],
                        $e2e,
                        null,
                        '[API_PAYOUT][OUTCOME]',
                    );
                }
                $withdrawal->refresh();
            } elseif ($acquirerService->getReference() === 'fyhub') {
                app(FyhubCashOutOutcomeService::class)->pollApiAndApplyIfTerminal($withdrawal);
                $withdrawal->refresh();
                // FYHUB cancela falha (saldo insuficiente) sem webhook e o status leva
                // alguns segundos pra refletir; re-poll assíncrono resolve em ~8-60s.
                if (! CashOutOutcomeApplier::isTerminalStatus((string) $withdrawal->status)) {
                    \App\Jobs\ReconcileFyhubPayoutJob::dispatch($withdrawal->id)->delay(now()->addSeconds(8));
                    \App\Jobs\ReconcileFyhubPayoutJob::dispatch($withdrawal->id)->delay(now()->addSeconds(25));
                    \App\Jobs\ReconcileFyhubPayoutJob::dispatch($withdrawal->id)->delay(now()->addSeconds(60));
                }
            } elseif ($acquirerService->getReference() === 'treeal') {
                app(TreealCashOutOutcomeService::class)->pollApiAndApplyIfTerminal($withdrawal);
                $withdrawal->refresh();
            }

            if ($acquirerService->getReference() === 'simpay') {
                app(\App\Services\Simpay\SimpayCashOutOutcomeService::class)->pollApiAndApplyIfTerminal($withdrawal);
                $withdrawal->refresh();
            }

            if ($acquirerService instanceof FluxPaymentsPixAcquirerService) {
                app(FluxPaymentsCashOutOutcomeService::class)->pollApiAndApplyIfTerminal($withdrawal);
                $withdrawal->refresh();
            }

            Helper::calculaSaldoLiquido($user->user_id ?? $user->username);
            app(\App\Services\PaymentProcessingService::class)->invalidateCachesAfterPayment($withdrawal->user_id);

            // Saque terminou negativo já na resposta síncrona (ex.: SIMPAY cancela por
            // saldo insuficiente da master). Valor já devolvido pelo OutcomeApplier.
            // Envelope 'error' p/ o integrador não interpretar como saque efetivado.
            $finalStatus = (string) $withdrawal->status;
            if (in_array($finalStatus, ['CANCELLED', 'FAILED', 'REFUNDED'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Saque não realizado. O valor foi devolvido ao saldo.',
                    'data' => [
                        'transaction_id' => $idTxn,
                        'amount' => $amount,
                        'pixKeyType' => $keyType,
                        'pixKey' => $keyValue,
                        'description' => $description,
                        'status' => $finalStatus,
                        'tipo_processamento' => 'Automático',
                        'created_at' => now()->toISOString(),
                        'adquirente' => $acquirerService->getReference(),
                    ],
                ], 200);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Saque PIX processado.',
                'data' => [
                    'transaction_id' => $idTxn,
                    'amount' => $amount,
                    'pixKeyType' => $keyType,
                    'pixKey' => $keyValue,
                    'description' => $description,
                    'status' => $withdrawal->status,
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
     * Persiste FAILED quando o saldo DO CLIENTE não cobre valor + taxa.
     * (Não tem relação com o saldo da Coratri na adquirente — nome legado.)
     * Sempre grava em solicitacoes_cash_out (comprovação operacional), mesmo sem callback.
     * Webhook só dispara se houver baasPostbackUrl válido.
     * Mensagem ao cliente permanece genérica (não expõe saldo).
     */
    private function registrarFalhaSaldoCoratri(
        Request $request,
        User $user,
        float $amountRequested,
        float $saldoDisponivel,
        float $valorTotalNecessario,
        float $taxaCashOut = 0.0,
    ): void {
        $callbackUrl = $request->filled('baasPostbackUrl') && $request->baasPostbackUrl !== 'web'
            ? trim((string) $request->baasPostbackUrl)
            : null;
        if ($callbackUrl === '') {
            $callbackUrl = null;
        }

        $idTransaction = 'PAYOUT_API_'.preg_replace('/[^a-zA-Z0-9]/', '', Str::uuid()->toString());
        $messageWebhook = 'Não foi possível sacar, entre em contato com o suporte.';
        $breakdown = app(\App\Services\BalanceService::class)->getBalanceBreakdown($user);
        $provaInterna = sprintf(
            'disp=%.2f need=%.2f taxa=%.2f bruto=%.2f med=%.2f',
            $saldoDisponivel,
            $valorTotalNecessario,
            $taxaCashOut,
            (float) ($breakdown['saldo_bruto'] ?? 0),
            (float) ($breakdown['saldo_em_mediacao'] ?? 0),
        );

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
                'taxa_cash_out' => round($taxaCashOut, 2),
                'valor_total_descontado' => round($valorTotalNecessario, 4),
                'cash_out_liquido' => $amountRequested,
                'descricao_transacao' => 'SALDO_INSUFICIENTE_API',
                'descricao_externa' => substr($provaInterna, 0, 255),
                'callback' => $callbackUrl,
            ]);

            if ($callbackUrl) {
                ClientWebhookDispatchJob::send(
                    $callbackUrl,
                    $idTransaction,
                    'FAILED',
                    $amountRequested,
                    now()->toIso8601String(),
                    ClientWebhookPayloadBuilder::extraForCashOut($row),
                    $messageWebhook
                );
            }

            Log::warning('[PIXOUT] FAILED por saldo do CLIENTE insuficiente', [
                'user_id' => $user->username,
                'amount_solicitado' => $amountRequested,
                'taxa_cash_out' => $taxaCashOut,
                'valor_total_necessario' => $valorTotalNecessario,
                'saldo_disponivel' => $saldoDisponivel,
                'saldo_bruto' => $breakdown['saldo_bruto'] ?? null,
                'saldo_em_mediacao' => $breakdown['saldo_em_mediacao'] ?? null,
                'callback' => $callbackUrl,
                'webhook_enviado' => (bool) $callbackUrl,
                'transaction_id' => $idTransaction,
                'cash_out_id' => $row->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SaqueController::registrarFalhaSaldoCoratri - Erro ao criar registro ou disparar webhook', [
                'error' => $e->getMessage(),
                'user_id' => $user->username,
                'amount_solicitado' => $amountRequested,
                'saldo_disponivel' => $saldoDisponivel,
                'valor_total_necessario' => $valorTotalNecessario,
            ]);
        }
    }
}
