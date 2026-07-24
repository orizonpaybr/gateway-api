<?php

namespace App\Http\Controllers\Api;

use App\Constants\UserPermission;
use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Http\Requests\WithdrawalIndexRequest;
use App\Http\Requests\WithdrawalStatsRequest;
use App\Models\App;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Jobs\ClientWebhookDispatchJob;
use App\Services\AffiliateCommissionService;
use App\Services\BalanceService;
use App\Services\CashOut\CashOutClientCallbackResolver;
use App\Services\CashOut\CashOutOutcomeApplier;
use App\Services\ClientWebhookPayloadBuilder;
use App\Services\FinancialService;
use App\Services\FluxPayments\FluxPaymentsCashOutOutcomeService;
use App\Services\Fyhub\FyhubCashOutOutcomeService;
use App\Services\Fyhub\FyhubPixAcquirerService;
use App\Services\Treeal\TreealCashOutOutcomeService;
use App\Services\Treeal\TreealPixAcquirerService;
use App\Services\PixAcquirer\PixAcquirerManager;
use App\Services\Simpay\SimpayCashOutOutcomeService;
use App\Services\WithdrawalStatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WithdrawalController extends Controller
{
    /** Taxa fixa padrão da aplicação para cash out (saque) quando não há customização - R$ 1,00 */
    private const TAXA_APLICACAO_CASH_OUT_PADRAO = 1.00;

    public function __construct(
        private readonly WithdrawalStatsService $statsService,
        private readonly FinancialService $financialService,
    ) {}

    /**
     * Retorna a taxa efetiva de saque para o usuário do saque (respeita taxas customizadas pelo admin).
     */
    private function getTaxaEfetivaSaque(SolicitacoesCashOut $saque, ?App $setting = null): float
    {
        $setting = $setting ?? Cache::remember('app_settings', 300, fn () => App::first());
        if (! $setting) {
            return self::TAXA_APLICACAO_CASH_OUT_PADRAO;
        }
        $user = $saque->relationLoaded('user') ? $saque->user : User::where('user_id', $saque->user_id)->first();
        if (! $user) {
            return (float) ($setting->taxa_fixa_pix ?? self::TAXA_APLICACAO_CASH_OUT_PADRAO);
        }
        try {
            $adquirenteRef = $saque->executor_ordem
                ?: Helper::adquirenteDefault($user->user_id, 'pix');
            $resultado = \App\Helpers\TaxaSaqueHelper::calcularTaxaSaque((float) $saque->amount, $setting, $user, true, false, $adquirenteRef);

            return (float) $resultado['taxa_cash_out'];
        } catch (\Throwable $e) {
            Log::warning('WithdrawalController::getTaxaEfetivaSaque - fallback para taxa padrão', [
                'saque_id' => $saque->id,
                'error' => $e->getMessage(),
            ]);

            return self::TAXA_APLICACAO_CASH_OUT_PADRAO;
        }
    }

    /**
     * Listar solicitações de saque com filtros e paginação
     */
    public function index(WithdrawalIndexRequest $request)
    {
        try {
            // Inputs saneados (validados pelo FormRequest)
            $validated = $request->validated();

            $perPage = (int) ($validated['limit'] ?? 20);
            $perPage = max(1, min($perPage, 100)); // limites seguros
            $page = max(1, (int) ($validated['page'] ?? 1));

            // CORRIGIDO: Normalizar status antes de validar para tratar 'all' corretamente
            $statusInput = strtolower((string) ($validated['status'] ?? 'pending'));
            $status = match ($statusInput) {
                'pending' => 'PENDING',
                'completed' => 'COMPLETED',
                'paid_out' => 'PAID_OUT',
                'cancelled' => 'CANCELLED',
                'failed' => 'FAILED',
                'processing' => 'PROCESSING',
                'all' => 'ALL',
                default => 'PENDING'
            };

            $search = trim((string) ($validated['busca'] ?? ''));
            if (mb_strlen($search) > 100) {
                $search = mb_substr($search, 0, 100);
            }

            $dataInicio = $validated['data_inicio'] ?? null;
            $dataFim = $validated['data_fim'] ?? null;

            $tipo = strtolower((string) ($validated['tipo'] ?? 'all')); // 'manual', 'automatico', 'all'
            $tipo = in_array($tipo, ['manual', 'automatico', 'all']) ? $tipo : 'all';

            // Query base
            // CORRIGIDO: Incluir todos os tipos de saques (WEB, MANUAL, AUTOMATICO) para aparecerem na listagem de aprovação
            $query = SolicitacoesCashOut::query()
                ->with(['user:id,username,email,user_id'])
                ->select([
                    'id', 'user_id', 'externalreference', 'beneficiaryname', 'beneficiarydocument',
                    'pix', 'pixkey', 'amount', 'taxa_cash_out', 'cash_out_liquido', 'status',
                    'executor_ordem', 'descricao_transacao', 'date', 'created_at', 'updated_at',
                ])
                ->whereIn('descricao_transacao', ['WEB', 'MANUAL', 'AUTOMATICO']);

            // CORRIGIDO: Filtro de status - verificar 'ALL' após normalização
            if ($status && $status !== 'ALL') {
                $query->where('status', $status);
            }

            // CORRIGIDO: Filtro de tipo (manual/automático) - garantir que funciona corretamente
            if ($tipo === 'manual') {
                $query->whereNull('executor_ordem');
            } elseif ($tipo === 'automatico') {
                $query->whereNotNull('executor_ordem');
            }
            // Se $tipo === 'all', não aplica filtro (mostra todos)

            // Filtro de busca (nome, documento, ID)
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('beneficiaryname', 'LIKE', "%{$search}%")
                        ->orWhere('beneficiarydocument', 'LIKE', "%{$search}%")
                        ->orWhere('id', 'LIKE', "%{$search}%")
                        ->orWhere('externalreference', 'LIKE', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('username', 'LIKE', "%{$search}%")
                                ->orWhere('email', 'LIKE', "%{$search}%");
                        });
                });
            }

            // Filtro de data
            if ($dataInicio) {
                $query->whereDate('date', '>=', $dataInicio);
            }
            if ($dataFim) {
                $query->whereDate('date', '<=', $dataFim);
            }

            // Ordenação: mais recentemente atualizados primeiro (aprovações/rejeições sobem para o topo)
            $query->orderByDesc('updated_at')->orderByDesc('id');

            // Paginação
            $saques = $query->paginate($perPage, ['*'], 'page', $page);

            $setting = Cache::remember('app_settings', 300, fn () => App::first());

            $data = $saques->map(function ($saque) use ($setting) {
                return [
                    'id' => $saque->id,
                    'transaction_id' => $saque->externalreference,
                    'user_id' => $saque->user_id,
                    'username' => $saque->user ? $saque->user->username : 'N/A',
                    'email' => $saque->user ? $saque->user->email : 'N/A',
                    'nome_cliente' => $saque->beneficiaryname,
                    'documento' => $saque->beneficiarydocument,
                    'pix_key' => $saque->pixkey,
                    'pix_type' => $saque->pix,
                    'amount' => (float) $saque->amount,
                    'taxa' => (float) $this->getTaxaEfetivaSaque($saque, $setting),
                    'valor_liquido' => (float) $saque->cash_out_liquido,
                    'status' => $saque->status,
                    'status_legivel' => $this->getStatusLabel($saque->status),
                    'tipo_processamento' => $saque->executor_ordem ? 'Automático' : 'Manual',
                    'executor' => $saque->executor_ordem,
                    'data' => $saque->date,
                    'created_at' => $saque->created_at,
                    'updated_at' => $saque->updated_at,
                    'descricao' => $saque->descricao_transacao ?? 'Saque PIX',
                    'end_to_end' => $saque->end_to_end ?? null, // Coluna pode não existir em todas as bases
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'data' => $data,
                    'current_page' => $saques->currentPage(),
                    'last_page' => $saques->lastPage(),
                    'per_page' => $saques->perPage(),
                    'total' => $saques->total(),
                    'from' => $saques->firstItem(),
                    'to' => $saques->lastItem(),
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao listar saques', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar saques.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Buscar detalhes de uma solicitação específica
     */
    public function show($id)
    {
        try {
            $saque = SolicitacoesCashOut::with('user')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $saque->id,
                    'transaction_id' => $saque->externalreference,
                    'id_transaction_gateway' => $saque->idTransaction,
                    'user_id' => $saque->user_id,
                    'username' => $saque->user ? $saque->user->username : 'N/A',
                    'email' => $saque->user ? $saque->user->email : 'N/A',
                    'nome_cliente' => $saque->beneficiaryname,
                    'documento' => $saque->beneficiarydocument,
                    'pix_key' => $saque->pixkey,
                    'pix_type' => $saque->pix,
                    'amount' => (float) $saque->amount,
                    'taxa' => (float) $this->getTaxaEfetivaSaque($saque),
                    'valor_liquido' => (float) $saque->cash_out_liquido,
                    'status' => $saque->status,
                    'status_legivel' => $this->getStatusLabel($saque->status),
                    'tipo_processamento' => $saque->executor_ordem ? 'Automático' : 'Manual',
                    'executor' => $saque->executor_ordem,
                    'data' => $saque->date,
                    'created_at' => $saque->created_at,
                    'updated_at' => $saque->updated_at,
                    'descricao' => $saque->descricao_transacao ?? 'Saque PIX',
                    'end_to_end' => $saque->end_to_end ?? null, // Coluna pode não existir em todas as bases
                    'descricao_externa' => $saque->descricao_externa ?? null,
                    'callback' => $saque->callback ?? null,
                    'user_balance' => $saque->user ? (float) $saque->user->saldo : 0,
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar detalhes do saque', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Saque não encontrado.',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Aprovar uma solicitação de saque.
     * Apenas saques MANUAIS (PENDING) são aprovados aqui. Saque automático é processado na hora (PixKeyController/SaqueController).
     * Valor + taxa já foram debitados na criação do saque manual; aqui só enviamos ao adquirente e atualizamos o registro.
     */
    public function approve($id, Request $request)
    {
        try {
            // Verificar se o usuário tem permissão (Admin ou Gerente)
            $user = $request->user();
            if (! in_array($user->permission, [UserPermission::ADMIN, UserPermission::MANAGER], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você não tem permissão para aprovar saques.',
                ], 403);
            }

            // Buscar saque com lock para evitar processamento duplicado
            $saque = SolicitacoesCashOut::lockForUpdate()->findOrFail($id);

            // Verificar se já foi processado
            if ($saque->status !== 'PENDING') {
                return response()->json([
                    'success' => false,
                    'message' => 'Este saque já foi processado.',
                ], 400);
            }

            // Buscar usuário do saque
            $userSaque = User::where('user_id', $saque->user_id)->first();
            if (! $userSaque) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário do saque não encontrado.',
                ], 404);
            }

            // Valor + taxa já foram debitados na criação do saque; na aprovação só enviamos ao adquirente e atualizamos o registro
            $taxaEfetiva = $this->getTaxaEfetivaSaque($saque);

            // Adquirente do usuário do saque (manual nasce com executor_ordem null)
            $adquirente = $saque->executor_ordem
                ?: Helper::adquirenteDefault($userSaque->user_id, 'pix');

            if (! $adquirente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum adquirente configurado.',
                ], 500);
            }

            if (strtolower($adquirente) === 'pagarme') {
                return response()->json([
                    'success' => false,
                    'message' => 'Este método de pagamento não suporta saques PIX.',
                ], 500);
            }

            Log::info('[MANUAL_APPROVE] Enviando saque ao adquirente', [
                'payout_id' => $saque->id,
                'user_id' => $userSaque->user_id,
                'adquirente' => $adquirente,
                'callback' => $saque->callback,
            ]);

            $response = $this->approveWithPixAcquirer($adquirente, $saque, $userSaque, $taxaEfetiva);
            if ($response->getStatusCode() === 200) {
                $this->statsService->invalidateCache();
                $this->financialService->invalidateWalletsCache();
                $this->financialService->invalidateStatsCache();
                app(\App\Services\PaymentProcessingService::class)->invalidateCachesAfterPayment($saque->user_id);
            }

            return $response;

        } catch (\Exception $e) {
            Log::error('Erro ao aprovar saque', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao aprovar saque: '.$e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Integração de aprovação automática por adquirente removida temporariamente.
     */
    private function approveWithPixAcquirer(
        string $adquirente,
        SolicitacoesCashOut $saque,
        User $userSaque,
        float $taxaCashOut
    ) {
        $acquirerManager = app(PixAcquirerManager::class);
        $acquirerService = $acquirerManager->resolve($adquirente);

        if (! $acquirerService->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'PIX temporariamente indisponível.',
            ], 503);
        }

        $correlationID = preg_replace('/[^a-zA-Z0-9]/', '', str()->uuid()->toString());
        $recipientName = trim((string) ($saque->beneficiaryname ?? ''));
        if ($recipientName === '') {
            $recipientName = $userSaque->name ?? 'Não informado';
        }

        $pixKeyTypeNorm = strtolower((string) ($saque->pixkey ?? ''));
        $recipientDocument = in_array($pixKeyTypeNorm, ['cpf', 'cnpj'], true)
            ? preg_replace('/\D/', '', (string) $saque->pix)
            : preg_replace('/\D/', '', (string) ($userSaque->cpf_cnpj ?? ''));
        if ($recipientDocument === '') {
            $recipientDocument = null;
        }

        // Taxa por dentro: paga-se o líquido (valor - taxa). Fallback para amount em
        // registros antigos que não tenham cash_out_liquido preenchido.
        $valorPagar = (float) ($saque->cash_out_liquido ?: $saque->amount);

        $payoutResult = $acquirerService->createPayout(
            $valorPagar,
            $saque->pix,
            $saque->pixkey,
            'Saque aprovado manualmente - ID: '.$saque->id,
            $correlationID,
            $recipientName,
            $recipientDocument
        );

        if (! ($payoutResult['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $payoutResult['message'] ?? 'Erro ao processar saque PIX',
            ], 500);
        }

        $idTxnRaw = $payoutResult['referenceCode'] ?? $correlationID;
        $idTxn = is_string($idTxnRaw) ? trim($idTxnRaw) : (string) $idTxnRaw;
        if ($idTxn === '') {
            $idTxn = $correlationID;
        }

        $raw = is_array($payoutResult['raw'] ?? null) ? $payoutResult['raw'] : [];
        $e2e = null;
        if ($raw !== []) {
            $e = $raw['endToEndId'] ?? $raw['endToEndid'] ?? null;
            $e2e = is_string($e) && $e !== '' ? $e : null;
        }

        $providerStatus = (string) ($payoutResult['status'] ?? 'pending');
        $statusMapped = $acquirerService instanceof FyhubPixAcquirerService
            || $acquirerService instanceof TreealPixAcquirerService
            ? $acquirerService->resolveInitialPayoutStatus($providerStatus, $e2e)
            : $acquirerService->mapPayoutStatus($providerStatus);

        $statusForDb = CashOutOutcomeApplier::isTerminalStatus($statusMapped)
            ? 'PROCESSING'
            : $statusMapped;

        DB::transaction(function () use ($saque, $idTxn, $statusForDb, $taxaCashOut, $correlationID, $acquirerService, $e2e, $adquirente) {
            $saqueAtualizado = SolicitacoesCashOut::where('id', $saque->id)
                ->lockForUpdate()
                ->first();

            if ($saqueAtualizado === null || in_array($saqueAtualizado->status, ['COMPLETED', 'PAID_OUT'], true)) {
                return;
            }

            $saqueAtualizado->update([
                'status' => $statusForDb,
                'externalreference' => $idTxn,
                'idTransaction' => $idTxn,
                'end_to_end' => $e2e,
                'executor_ordem' => $acquirerService->getReference(),
                'adquirente_ref' => $adquirente,
                'taxa_cash_out' => $taxaCashOut,
                'descricao_externa' => $correlationID,
            ]);
        });

        $saque->refresh();

        if (CashOutOutcomeApplier::isTerminalStatus($statusMapped)) {
            if ($acquirerService->getReference() === 'fyhub') {
                app(FyhubCashOutOutcomeService::class)->applySyncTerminalOutcome(
                    $saque,
                    $statusMapped,
                    $raw,
                    $e2e,
                    '[MANUAL_APPROVE][OUTCOME]',
                );
            } elseif ($acquirerService->getReference() === 'treeal') {
                app(TreealCashOutOutcomeService::class)->applySyncTerminalOutcome(
                    $saque,
                    $statusMapped,
                    $raw,
                    $e2e,
                    '[MANUAL_APPROVE][OUTCOME]',
                );
            } else {
                app(CashOutOutcomeApplier::class)->applyTerminalStatusIfNeeded(
                    $saque,
                    $statusMapped,
                    $raw,
                    $e2e,
                    null,
                    '[MANUAL_APPROVE][OUTCOME]',
                );
            }
            $saque->refresh();
        } elseif ($acquirerService->getReference() === 'fyhub') {
            app(FyhubCashOutOutcomeService::class)->pollApiAndApplyIfTerminal($saque);
            $saque->refresh();
        } elseif ($acquirerService->getReference() === 'treeal') {
            app(TreealCashOutOutcomeService::class)->pollApiAndApplyIfTerminal($saque);
            $saque->refresh();
        }

        if ($acquirerService->getReference() === 'simpay') {
            app(SimpayCashOutOutcomeService::class)->pollApiAndApplyIfTerminal($saque);
            $saque->refresh();
        }

        if ($acquirerService->getReference() === 'fluxpayments') {
            app(FluxPaymentsCashOutOutcomeService::class)->pollApiAndApplyIfTerminal($saque);
            $saque->refresh();
        }

        Helper::calculaSaldoLiquido($userSaque->user_id);
        app(\App\Services\PaymentProcessingService::class)->invalidateCachesAfterPayment($saque->user_id);

        return response()->json([
            'success' => true,
            'message' => 'Saque aprovado e processado com sucesso.',
            'data' => [
                'transaction_id' => $saque->idTransaction,
                'status' => $saque->status,
            ],
        ], 200);
    }

    /**
     * Rejeitar uma solicitação de saque.
     * Apenas saques MANUAIS (PENDING) chegam aqui. Saque automático é processado na hora e não passa por aprovação/rejeição.
     * Na rejeição devolvemos valor + taxa ao usuário (foram debitados na criação do saque manual).
     */
    public function reject($id, Request $request)
    {
        try {
            // Verificar se o usuário tem permissão (Admin ou Gerente)
            $user = $request->user();
            if (! in_array($user->permission, [UserPermission::ADMIN, UserPermission::MANAGER], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você não tem permissão para rejeitar saques.',
                ], 403);
            }

            // Buscar saque
            $saque = SolicitacoesCashOut::findOrFail($id);

            // Verificar se já foi processado
            if ($saque->status !== 'PENDING') {
                return response()->json([
                    'success' => false,
                    'message' => 'Este saque já foi processado.',
                ], 400);
            }

            DB::transaction(function () use ($saque) {
                $locked = SolicitacoesCashOut::where('id', $saque->id)->lockForUpdate()->first();
                if (! $locked || $locked->status !== 'PENDING') {
                    throw new \RuntimeException('Este saque já foi processado.');
                }

                $locked->update(['status' => 'CANCELLED']);

                if (! $locked->user_id) {
                    return;
                }

                $userModel = \App\Services\WithdrawalFailureRefundService::resolveUserForCashOut($locked);
                if (! $userModel) {
                    Log::warning("Usuário não encontrado ao rejeitar o saque ID: {$locked->id}, user_id: {$locked->user_id}");

                    return;
                }

                User::where('id', $userModel->id)->increment('transacoes_recused', 1);

                $valorDevolver = $locked->valor_total_descontado !== null && (float) $locked->valor_total_descontado > 0
                    ? (float) $locked->valor_total_descontado
                    : (float) $locked->amount + (float) ($locked->taxa_cash_out ?? 0);

                if ($valorDevolver > 0) {
                    if (\App\Services\WithdrawalFailureRefundService::debitAlreadyCleared($locked)) {
                        Log::info('WithdrawalController::reject - Débito já estornado, skip', [
                            'saque_id' => $locked->id,
                        ]);
                    } elseif (\App\Services\WithdrawalFailureRefundService::hasRecordedDebit($locked)
                        || ($locked->debito_saldo_principal === null && $locked->debito_saldo_afiliado === null)) {
                        $balanceService = app(BalanceService::class);
                        $debAf = $locked->debito_saldo_afiliado;
                        $debPr = $locked->debito_saldo_principal;

                        if ($debAf !== null && $debPr !== null
                            && ((float) $debAf > 0 || (float) $debPr > 0)) {
                            $a = round((float) $debAf, 4);
                            $p = round((float) $debPr, 4);
                            if (abs(($a + $p) - round($valorDevolver, 4)) > 0.02) {
                                $balanceService->incrementBalance($userModel, $valorDevolver, 'saldo');
                            } else {
                                $balanceService->incrementCombinedBalanceMirror($userModel, $a, $p);
                            }
                        } else {
                            $balanceService->incrementBalance($userModel, $valorDevolver, 'saldo');
                        }

                        \App\Services\WithdrawalFailureRefundService::clearDebitMarkers($locked);
                    }
                }

                app(AffiliateCommissionService::class)->reverseCashOutCommissionForFailedWithdrawal($locked);

                Log::info('WithdrawalController::reject - Valor e taxa devolvidos ao usuário', [
                    'saque_id' => $locked->id,
                    'user_id' => $userModel->user_id,
                    'valor_devolvido' => $valorDevolver,
                ]);
            });

            $saque->refresh();

            if ($saque->user_id) {
                Helper::calculaSaldoLiquido($saque->user_id);
            }

            if ($saque->user_id) {
                app(\App\Services\PaymentProcessingService::class)->invalidateCachesAfterPayment($saque->user_id);
            }
            $this->statsService->invalidateCache();
            $this->financialService->invalidateWalletsCache();
            $this->financialService->invalidateStatsCache();

            $this->dispatchRejectionWebhook($saque);

            return response()->json([
                'success' => true,
                'message' => 'Saque rejeitado com sucesso.',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao rejeitar saque', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao rejeitar saque.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Dispara webhook ao cliente informando que o saque manual foi rejeitado pelo admin.
     */
    private function dispatchRejectionWebhook(SolicitacoesCashOut $saque): void
    {
        $callbackUrl = CashOutClientCallbackResolver::resolve($saque);
        if ($callbackUrl === null) {
            Log::info('[MANUAL_REJECT] Postback não enviado (sem URL de callback)', [
                'payout_id' => $saque->id,
                'transaction_id' => $saque->idTransaction,
                'user_id' => $saque->user_id,
            ]);

            return;
        }

        ClientWebhookDispatchJob::send(
            $callbackUrl,
            $saque->idTransaction ?? $saque->externalreference,
            'CANCELLED',
            (float) $saque->amount,
            now()->toIso8601String(),
            ClientWebhookPayloadBuilder::extraForCashOut($saque),
            'Saque rejeitado.'
        );

        Log::info('[MANUAL_REJECT] Postback CANCELLED enviado', [
            'payout_id' => $saque->id,
            'transaction_id' => $saque->idTransaction,
            'callback' => $callbackUrl,
        ]);
    }

    /**
     * Obter estatísticas de saques
     */
    public function stats(WithdrawalStatsRequest $request)
    {
        try {
            $periodo = $request->validated()['periodo'] ?? 'hoje';

            $stats = $this->statsService->calculate($periodo);

            return response()->json([
                'success' => true,
                'data' => [
                    'periodo' => $periodo,
                    'data_inicio' => $stats['periodo']['inicio'],
                    'data_fim' => $stats['periodo']['fim'],
                    'total_pendentes' => $stats['totais']['pendentes'],
                    'total_aprovados' => $stats['totais']['aprovados'],
                    'total_rejeitados' => $stats['totais']['rejeitados'],
                    'valor_total' => (float) $stats['valores']['total'],
                    'valor_aprovado' => (float) $stats['valores']['aprovado'],
                    'saques_manuais' => $stats['tipos']['manuais'],
                    'saques_automaticos' => $stats['tipos']['automaticos'],
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar estatísticas de saques', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar estatísticas.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obter label legível do status
     */
    private function getStatusLabel($status)
    {
        $labels = [
            'PENDING' => 'Pendente',
            'WAITING_FOR_APPROVAL' => 'Pendente',
            'COMPLETED' => 'Concluído',
            'PAID_OUT' => 'Pago',
            'CANCELLED' => 'Cancelado',
            'FAILED' => 'Falhou',
            'PROCESSING' => 'Concluído',
        ];

        return $labels[$status] ?? $status;
    }

    /**
     * Obter data inicial baseada no período
     */
    private function getDataInicioPeriodo($periodo)
    {
        switch ($periodo) {
            case 'hoje':
                return now()->startOfDay();
            case '7d':
                return now()->subDays(7)->startOfDay();
            case '30d':
                return now()->subDays(30)->startOfDay();
            case 'mes':
                return now()->startOfMonth();
            default:
                return now()->startOfDay();
        }
    }

    /**
     * Obter configurações de saque
     */
    public function getConfig(Request $request)
    {
        try {
            // Usar cache para reduzir I/O em configurações globais
            $config = Cache::remember('app_settings', 300, function () {
                return \App\Models\App::first();
            });

            // Se não existir configuração, retornar valores padrão
            if (! $config) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'saque_automatico' => false,
                        'limite_saque_automatico' => null,
                    ],
                ], 200);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'saque_automatico' => (bool) $config->saque_automatico,
                    // Mapear 0 (persistido para bases NOT NULL) para null (sem limite)
                    'limite_saque_automatico' => ($config->limite_saque_automatico === 0 || $config->limite_saque_automatico === '0.00')
                        ? null
                        : (float) $config->limite_saque_automatico,
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar configurações de saque', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar configurações.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Atualizar configurações de saque
     */
    public function updateConfig(Request $request)
    {
        try {
            // Verificar se o usuário tem permissão (Apenas Admin)
            $user = $request->user();
            if ($user->permission != UserPermission::ADMIN) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você não tem permissão para atualizar configurações.',
                ], 403);
            }

            $request->validate([
                'saque_automatico' => 'required|boolean',
                'limite_saque_automatico' => 'nullable|numeric|min:0',
            ]);

            // Buscar configuração existente
            $config = \App\Models\App::first();

            // Se não existir, criar registro básico
            if (! $config) {
                try {
                    $config = \App\Models\App::create([
                        'saque_automatico' => false,
                        'limite_saque_automatico' => null,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Erro ao criar configuração de app', [
                        'error' => $e->getMessage(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Erro ao criar configurações. Verifique se a tabela app existe e tem os campos necessários.',
                        'error' => $e->getMessage(),
                    ], 500);
                }
            }

            // Interpretar vazio como NULL (sem limite)
            $limiteRaw = $request->input('limite_saque_automatico');
            $limite = null;
            if ($limiteRaw !== null && $limiteRaw !== '') {
                $limite = (float) str_replace(',', '.', $limiteRaw);
            }

            // Algumas bases têm a coluna como NOT NULL. Usar 0.00 como 'sem limite' e mapear para null no GET.
            $config->update([
                'saque_automatico' => $request->input('saque_automatico'),
                'limite_saque_automatico' => $limite === null ? 0 : $limite,
            ]);

            // Limpar cache de configurações
            \Illuminate\Support\Facades\Cache::forget('app_settings');

            return response()->json([
                'success' => true,
                'message' => 'Configurações de saque atualizadas com sucesso!',
                'data' => [
                    'saque_automatico' => (bool) $config->saque_automatico,
                    'limite_saque_automatico' => $config->limite_saque_automatico,
                ],
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro de validação',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar configurações de saque', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar configurações.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
