<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreManualDepositRequest;
use App\Http\Requests\Admin\StoreManualWithdrawalRequest;
use App\Services\{AdminTransactionService, FinancialService, CacheKeyService};
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controller para gerenciar transações manuais do admin
 * 
 * @package App\Http\Controllers\Api
 */
class AdminTransactionsController extends Controller
{
    /**
     * Serviços injetados via container
     */
    private FinancialService $financialService;
    private AdminTransactionService $transactionService;
    
    /**
     * Constructor com injeção de dependência
     */
    public function __construct(
        FinancialService $financialService,
        AdminTransactionService $transactionService
    ) {
        $this->financialService = $financialService;
        $this->transactionService = $transactionService;
    }
    
    /**
     * Criar depósito manual
     * 
     * @param StoreManualDepositRequest $request
     * @return JsonResponse
     */
    public function storeDeposit(StoreManualDepositRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->transactionService->findUser($validated['user_id']);
        if (!$user) {
            return $this->errorResponse('Usuário não encontrado.', 404);
        }

        // IMPORTANTE: Recarregar usuário do banco para garantir dados atualizados (evita cache)
        if ($user && isset($user->user_id)) {
            $user = \App\Models\User::where('user_id', $user->user_id)->first();
        }

        $settings = $this->transactionService->getAppSettings();
        if (!$settings) {
            return $this->errorResponse('Configurações da aplicação não foram encontradas.', 500);
        }

        $amount = (float) $validated['amount'];
        $description = $validated['description'] ?? 'MANUAL';

        DB::beginTransaction();

        try {
            // IMPORTANTE: Recarregar usuário novamente antes de calcular taxa (garantia extra)
            if ($user && isset($user->user_id)) {
                $user = \App\Models\User::where('user_id', $user->user_id)->first();
            }
            
            \Illuminate\Support\Facades\Log::info('AdminTransactionsController::storeDeposit - Dados antes do cálculo de taxa', [
                'user_id' => $user->user_id ?? 'N/A',
                'amount' => $amount,
                'taxas_personalizadas_ativas' => $user->taxas_personalizadas_ativas ?? false,
                'taxa_fixa_deposito' => $user->taxa_fixa_deposito ?? 'N/A',
                'taxa_fixa_padrao_global' => $settings->taxa_fixa_padrao ?? 'N/A',
            ]);
            
            $taxaCalculada = \App\Helpers\TaxaFlexivelHelper::calcularTaxaDeposito($amount, $settings, $user);
            
            \Illuminate\Support\Facades\Log::info('AdminTransactionsController::storeDeposit - Taxa calculada', [
                'amount' => $amount,
                'taxa_cash_in' => $taxaCalculada['taxa_cash_in'],
                'deposito_liquido' => $taxaCalculada['deposito_liquido'],
                'taxa_adquirente' => $taxaCalculada['taxa_adquirente'],
                'verificacao' => 'deposito_liquido = ' . $amount . ' - ' . $taxaCalculada['taxa_cash_in'] . ' = ' . $taxaCalculada['deposito_liquido'],
            ]);
            $depositoLiquido = $taxaCalculada['deposito_liquido'];
            $taxaCashIn = $taxaCalculada['taxa_cash_in'];
            // IMPORTANTE: Depósitos manuais não têm custo de adquirente (não passam pela TREEAL)
            // O TaxaFlexivelHelper retorna o custo TREEAL para referência, mas para depósitos manuais deve ser 0
            $taxaAdquirente = 0.0;

            $idTransaction = $this->transactionService->generateTransactionId();

            $deposit = $this->transactionService->createDepositRecord(
                $user,
                $amount,
                $depositoLiquido,
                $taxaCashIn,
                $description,
                $idTransaction,
                $taxaAdquirente // 0 para depósitos manuais (sem custo de adquirente)
            );

            // Usar BalanceService para operação thread-safe (já dentro de transação)
            $balanceService = app(\App\Services\BalanceService::class);
            $balanceService->incrementBalance($user, $depositoLiquido, 'saldo');
            \App\Helpers\Helper::calculaSaldoLiquido($user->user_id);
            
            // Registrar evento para auditoria
            $eventService = app(\App\Services\PaymentEventService::class);
            $balanceBefore = $user->saldo - $depositoLiquido;
            $eventService->recordPaymentReceived($deposit, $user, $balanceBefore, $user->fresh()->saldo);

            DB::commit();

            // Limpar caches relacionados (fail-safe)
            $this->clearRelatedCaches();

            return response()->json([
                'success' => true,
                'message' => 'Depósito manual criado com sucesso.',
                'data' => [
                    'deposit' => $this->formatDepositResponse($deposit, $user),
                ],
            ], 201);
        } catch (\Throwable $exception) {
            DB::rollBack();

            Log::error('Erro ao criar depósito manual', [
                'user_id' => $validated['user_id'],
                'amount' => $amount,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return $this->errorResponse('Não foi possível criar o depósito manual.', 500);
        }
    }
    
    /**
     * Criar saque manual
     * 
     * @param StoreManualWithdrawalRequest $request
     * @return JsonResponse
     */
    public function storeWithdrawal(StoreManualWithdrawalRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->transactionService->findUser($validated['user_id']);
        if (!$user) {
            return $this->errorResponse('Usuário não encontrado.', 404);
        }

        $settings = $this->transactionService->getAppSettings();
        if (!$settings) {
            return $this->errorResponse('Configurações da aplicação não foram encontradas.', 500);
        }

        $amount = (float) $validated['amount'];
        $description = $validated['description'] ?? 'MANUAL';

        // Calcular taxa de saque
        $taxaCalculada = \App\Helpers\TaxaSaqueHelper::calcularTaxaSaque($amount, $settings, $user, true, false);
        $saqueLiquido = $taxaCalculada['saque_liquido'];
        $taxaCashOut = $taxaCalculada['taxa_cash_out'];
        $valorTotalDescontar = $taxaCalculada['valor_total_descontar'];

        // Verificar se o usuário tem saldo suficiente
        if (!$this->transactionService->hasSufficientBalance($user, $valorTotalDescontar)) {
            return response()->json([
                'success' => false,
                'message' => 'Saldo insuficiente para realizar o saque.',
                'data' => [
                    'saldo_disponivel' => $user->saldo,
                    'valor_necessario' => $valorTotalDescontar,
                    'valor_saque' => $amount,
                    'taxa' => $taxaCashOut,
                ],
            ], 400);
        }

        DB::beginTransaction();

        try {
            $idTransaction = $this->transactionService->generateTransactionId();

            $withdrawal = $this->transactionService->createWithdrawalRecord(
                $user,
                $amount,
                $saqueLiquido,
                $taxaCashOut,
                $description,
                $idTransaction
            );

            // Usar BalanceService para operação thread-safe (já dentro de transação)
            $balanceService = app(\App\Services\BalanceService::class);
            $balanceBefore = $user->saldo;
            $balanceService->decrementBalance($user, $valorTotalDescontar, 'saldo');
            \App\Helpers\Helper::calculaSaldoLiquido($user->user_id);
            
            // Registrar evento para auditoria
            $eventService = app(\App\Services\PaymentEventService::class);
            $eventService->recordPaymentSent($withdrawal, $user, $balanceBefore, $user->fresh()->saldo);

            DB::commit();

            // Limpar caches relacionados (fail-safe)
            $this->clearRelatedCachesWithdrawal();

            return response()->json([
                'success' => true,
                'message' => 'Saque manual criado com sucesso.',
                'data' => [
                    'withdrawal' => $this->formatWithdrawalResponse($withdrawal, $user, $valorTotalDescontar),
                ],
            ], 201);
        } catch (\Throwable $exception) {
            DB::rollBack();

            Log::error('Erro ao criar saque manual', [
                'user_id' => $validated['user_id'],
                'amount' => $amount,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return $this->errorResponse('Não foi possível criar o saque manual.', 500);
        }
    }
    
    /**
     * Limpar caches relacionados após criar depósito
     * Fail-safe: não interrompe a operação se cache falhar
     * 
     * @return void
     */
    private function clearRelatedCaches(): void
    {
        try {
            $this->financialService->invalidateDepositsCache();
        } catch (\Throwable $exception) {
            Log::warning('Falha ao limpar cache financeiro após depósito manual', [
                'error' => $exception->getMessage(),
            ]);
        }
        
        try {
            CacheKeyService::forgetAdminRecentTransactions();
        } catch (\Throwable $exception) {
            Log::warning('Falha ao limpar cache de transações recentes do admin', [
                'error' => $exception->getMessage(),
            ]);
        }
    }
    
    /**
     * Limpar caches relacionados após criar saque
     * Fail-safe: não interrompe a operação se cache falhar
     * 
     * @return void
     */
    private function clearRelatedCachesWithdrawal(): void
    {
        try {
            $this->financialService->invalidateWithdrawalsCache();
        } catch (\Throwable $exception) {
            Log::warning('Falha ao limpar cache financeiro após saque manual', [
                'error' => $exception->getMessage(),
            ]);
        }
        
        try {
            CacheKeyService::forgetAdminRecentTransactions();
        } catch (\Throwable $exception) {
            Log::warning('Falha ao limpar cache de transações recentes do admin', [
                'error' => $exception->getMessage(),
            ]);
        }
    }
    
    /**
     * Formatar resposta de depósito
     * 
     * @param \App\Models\Solicitacoes $deposit
     * @param \App\Models\User $user
     * @return array
     */
    private function formatDepositResponse($deposit, $user): array
    {
        return [
            'id' => $deposit->id,
            'transaction_id' => $deposit->idTransaction,
            'amount' => $deposit->amount,
            'valor_liquido' => $deposit->deposito_liquido,
            'taxa' => $deposit->amount - $deposit->deposito_liquido,
            'status' => $deposit->status,
            'descricao' => $deposit->descricao_transacao,
            'created_at' => $deposit->created_at?->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'user_id' => $user->user_id,
                'name' => $user->name,
                'username' => $user->username,
            ],
        ];
    }
    
    /**
     * Formatar resposta de saque
     * 
     * @param \App\Models\SolicitacoesCashOut $withdrawal
     * @param \App\Models\User $user
     * @param float $valorTotalDescontado
     * @return array
     */
    private function formatWithdrawalResponse($withdrawal, $user, float $valorTotalDescontado): array
    {
        return [
            'id' => $withdrawal->id,
            'transaction_id' => $withdrawal->idTransaction,
            'amount' => $withdrawal->amount,
            'valor_liquido' => $withdrawal->cash_out_liquido,
            'taxa' => $withdrawal->taxa_cash_out,
            'valor_total_descontado' => $valorTotalDescontado,
            'status' => $withdrawal->status,
            'descricao' => $withdrawal->descricao_transacao,
            'created_at' => $withdrawal->created_at?->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'user_id' => $user->user_id,
                'name' => $user->name,
                'username' => $user->username,
            ],
        ];
    }
    
}

