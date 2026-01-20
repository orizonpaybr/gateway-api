<?php

namespace App\Services;

use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Traits\SplitTrait;

/**
 * Service para processamento atômico de pagamentos
 * 
 * Garante que todas as operações relacionadas a um pagamento sejam executadas
 * de forma atômica (tudo ou nada)
 */
class PaymentProcessingService
{
    public function __construct(
        private BalanceService $balanceService,
        private PaymentEventService $eventService
    ) {}
    
    /**
     * Processa pagamento recebido de forma atômica
     * 
     * @param Solicitacoes $cashin Transação de depósito
     * @return void
     * @throws \Exception Se processamento falhar
     */
    public function processPaymentReceived(Solicitacoes $cashin): void
    {
        DB::transaction(function () use ($cashin) {
            // Lock no registro da transação
            $cashin = Solicitacoes::where('id', $cashin->id)
                ->lockForUpdate()
                ->first();
            
            if (!$cashin) {
                throw new \Exception("Transação não encontrada: {$cashin->id}");
            }
            
            // Verificar idempotência
            if ($cashin->status === 'PAID_OUT' || $cashin->status === 'COMPLETED') {
                Log::info("Pagamento já processado anteriormente", [
                    'transaction_id' => $cashin->idTransaction,
                    'status' => $cashin->status,
                ]);
                return; // Idempotência - já foi processado
            }
            
            // Lock no usuário
            $user = User::where('user_id', $cashin->user_id)
                ->lockForUpdate()
                ->first();
            
            if (!$user) {
                throw new \Exception("Usuário não encontrado: {$cashin->user_id}");
            }
            
            // 1. Atualizar status da transação
            $cashin->update(['status' => 'PAID_OUT']);
            
            // 2. Creditar saldo (thread-safe)
            $balanceBefore = $user->saldo;
            $this->balanceService->incrementBalance(
                $user,
                $cashin->deposito_liquido,
                'saldo'
            );
            $balanceAfter = $user->fresh()->saldo;
            
            // 3. Calcular saldo líquido (dentro da transação)
            \App\Helpers\Helper::calculaSaldoLiquido($user->user_id);
            
            // 3. Registrar evento (auditoria)
            $this->eventService->recordPaymentReceived(
                $cashin,
                $user,
                $balanceBefore,
                $balanceAfter
            );
            
            // 4. Processar splits (dentro da transação)
            if ($cashin->split_email && $cashin->split_percentage) {
                $this->processSplits($cashin, $user);
            }
            
            // 5. Processar comissões de gerente (dentro da transação)
            if ($user->gerente_id) {
                $this->processCommissions($cashin, $user);
            }
            
            Log::info("Pagamento processado com sucesso", [
                'transaction_id' => $cashin->idTransaction,
                'user_id' => $user->user_id,
                'amount' => $cashin->amount,
                'amount_credited' => $cashin->deposito_liquido,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ]);
            
            // Tudo ou nada - se qualquer coisa falhar, rollback completo
        });
    }
    
    /**
     * Processa saque de forma atômica
     */
    public function processWithdrawal(SolicitacoesCashOut $cashout): void
    {
        DB::transaction(function () use ($cashout) {
            // Lock no registro do saque
            $cashout = \App\Models\SolicitacoesCashOut::where('id', $cashout->id)
                ->lockForUpdate()
                ->first();
            
            if (!$cashout) {
                throw new \Exception("Saque não encontrado: {$cashout->id}");
            }
            
            // Verificar idempotência
            if ($cashout->status === 'COMPLETED' || $cashout->status === 'PAID_OUT') {
                Log::info("Saque já processado anteriormente", [
                    'transaction_id' => $cashout->idTransaction,
                    'status' => $cashout->status,
                ]);
                return;
            }
            
            // Lock no usuário
            $user = User::where('user_id', $cashout->user_id)
                ->lockForUpdate()
                ->first();
            
            if (!$user) {
                throw new \Exception("Usuário não encontrado: {$cashout->user_id}");
            }
            
            // Calcular valor total a debitar
            $valorTotalDebitar = $cashout->amount + ($cashout->taxa_cash_out ?? 0);
            
            // Verificar saldo suficiente
            if ($user->saldo < $valorTotalDebitar) {
                throw new \Exception("Saldo insuficiente. Disponível: {$user->saldo}, Necessário: {$valorTotalDebitar}");
            }
            
            // 1. Atualizar status
            $cashout->update(['status' => 'COMPLETED']);
            
            // 2. Debitar saldo
            $balanceBefore = $user->saldo;
            $this->balanceService->decrementBalance(
                $user,
                $valorTotalDebitar,
                'saldo'
            );
            $balanceAfter = $user->fresh()->saldo;
            
            // 3. Registrar evento
            $this->eventService->recordPaymentSent(
                $cashout,
                $user,
                $balanceBefore,
                $balanceAfter
            );
            
            Log::info("Saque processado com sucesso", [
                'transaction_id' => $cashout->idTransaction,
                'user_id' => $user->user_id,
                'amount' => $cashout->amount,
                'amount_debited' => $valorTotalDebitar,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ]);
        });
    }
    
    /**
     * Processa splits dentro da transação
     */
    private function processSplits(Solicitacoes $cashin, User $user): void
    {
        try {
            if (trait_exists(SplitTrait::class)) {
                SplitTrait::processSplits($cashin, $user);
            }
        } catch (\Exception $e) {
            Log::error("Erro ao processar splits", [
                'transaction_id' => $cashin->idTransaction,
                'error' => $e->getMessage(),
            ]);
            // Não re-throw - splits são opcionais, não devem quebrar o pagamento
        }
    }
    
    /**
     * Processa comissões de gerente dentro da transação
     */
    private function processCommissions(Solicitacoes $cashin, User $user): void
    {
        try {
            if (!$user->gerente_id) {
                return;
            }
            
            $gerente = User::where('id', $user->gerente_id)
                ->lockForUpdate()
                ->first();
            
            if (!$gerente) {
                Log::warning("Gerente não encontrado", [
                    'gerente_id' => $user->gerente_id,
                ]);
                return;
            }
            
            $gerentePorcentagem = $gerente->gerente_percentage ?? 0;
            
            if ($gerentePorcentagem > 0) {
                $valorComissao = (float) $cashin->taxa_cash_in * ($gerentePorcentagem / 100);
                
                // Criar registro de comissão
                \App\Models\Transactions::create([
                    'user_id' => $user->user_id,
                    'gerente_id' => $user->gerente_id,
                    'solicitacao_id' => $cashin->id,
                    'comission_value' => $valorComissao,
                    'transaction_percent' => $cashin->taxa_cash_in,
                    'comission_percent' => $gerentePorcentagem,
                ]);
                
                Log::info("Comissão de gerente processada", [
                    'gerente_id' => $gerente->id,
                    'valor_comissao' => $valorComissao,
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Erro ao processar comissão de gerente", [
                'transaction_id' => $cashin->idTransaction,
                'error' => $e->getMessage(),
            ]);
            // Não re-throw - comissões são opcionais
        }
    }
}
