<?php

namespace App\Services;

use App\Models\PaymentEvent;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Carbon\Carbon;

/**
 * Service para Event Sourcing de transações financeiras
 * 
 * Registra todas as operações financeiras para auditoria completa
 */
class PaymentEventService
{
    /**
     * Registra evento de pagamento recebido (depósito)
     */
    public function recordPaymentReceived(
        Solicitacoes $cashin,
        User $user,
        float $balanceBefore,
        float $balanceAfter
    ): PaymentEvent {
        return PaymentEvent::create([
            'event_type' => 'PAYMENT_RECEIVED',
            'transaction_id' => $cashin->id,
            'transaction_type' => 'deposit',
            'user_id' => $user->id,
            'amount' => $cashin->amount,
            'amount_credited' => $cashin->deposito_liquido,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'metadata' => [
                'transaction_id' => $cashin->idTransaction,
                'adquirente' => $cashin->adquirente_ref,
                'webhook_id' => request()->header('X-Webhook-Id'),
                'processed_at' => now()->toIso8601String(),
                'taxa_cash_in' => $cashin->taxa_cash_in,
            ],
        ]);
    }
    
    /**
     * Registra evento de pagamento enviado (saque)
     */
    public function recordPaymentSent(
        SolicitacoesCashOut $cashout,
        User $user,
        float $balanceBefore,
        float $balanceAfter
    ): PaymentEvent {
        return PaymentEvent::create([
            'event_type' => 'PAYMENT_SENT',
            'transaction_id' => $cashout->id,
            'transaction_type' => 'withdrawal',
            'user_id' => $user->id,
            'amount' => $cashout->amount,
            'amount_debited' => $cashout->amount + ($cashout->taxa_cash_out ?? 0),
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'metadata' => [
                'transaction_id' => $cashout->idTransaction,
                'adquirente' => $cashout->executor_ordem,
                'processed_at' => now()->toIso8601String(),
                'taxa_cash_out' => $cashout->taxa_cash_out ?? 0,
            ],
        ]);
    }
    
    /**
     * Registra evento de pagamento revertido
     */
    public function recordPaymentReversed(
        Solicitacoes $cashin,
        User $user,
        float $balanceBefore,
        float $balanceAfter,
        string $reason
    ): PaymentEvent {
        return PaymentEvent::create([
            'event_type' => 'PAYMENT_REVERSED',
            'transaction_id' => $cashin->id,
            'transaction_type' => 'deposit',
            'user_id' => $user->id,
            'amount' => $cashin->amount,
            'amount_debited' => $cashin->deposito_liquido,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'metadata' => [
                'transaction_id' => $cashin->idTransaction,
                'reason' => $reason,
                'processed_at' => now()->toIso8601String(),
            ],
        ]);
    }
    
    /**
     * Reconstrói saldo a partir de eventos
     * 
     * @param User $user
     * @param Carbon|null $fromDate Data inicial (opcional)
     * @return float Saldo reconstruído
     */
    public function reconstructBalance(User $user, ?Carbon $fromDate = null): float
    {
        $query = PaymentEvent::where('user_id', $user->id)
            ->orderBy('created_at');
        
        if ($fromDate) {
            $query->where('created_at', '>=', $fromDate);
        }
        
        $balance = 0;
        foreach ($query->get() as $event) {
            if ($event->amount_credited) {
                $balance += (float) $event->amount_credited;
            }
            if ($event->amount_debited) {
                $balance -= (float) $event->amount_debited;
            }
        }
        
        return $balance;
    }
    
    /**
     * Obtém histórico de eventos de um usuário
     */
    public function getUserEvents(User $user, ?Carbon $fromDate = null, int $limit = 100)
    {
        $query = PaymentEvent::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit);
        
        if ($fromDate) {
            $query->where('created_at', '>=', $fromDate);
        }
        
        return $query->get();
    }
}
