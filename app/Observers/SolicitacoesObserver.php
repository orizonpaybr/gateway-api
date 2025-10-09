<?php

namespace App\Observers;

use App\Models\Solicitacoes;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\Log;

class SolicitacoesObserver
{
    private $pushService;

    public function __construct(PushNotificationService $pushService)
    {
        $this->pushService = $pushService;
    }

    /**
     * Handle the Solicitacoes "created" event.
     */
    public function created(Solicitacoes $solicitacoes): void
    {
        //
    }

    /**
     * Handle the Solicitacoes "updated" event.
     */
    public function updated(Solicitacoes $solicitacoes): void
    {
        Log::info('[OBSERVER] SolicitacoesObserver::updated chamado', [
            'solicitacao_id' => $solicitacoes->id,
            'old_status' => $solicitacoes->getOriginal('status'),
            'new_status' => $solicitacoes->status,
            'wasChanged' => $solicitacoes->wasChanged('status'),
            'user_id' => $solicitacoes->user_id
        ]);
        
        // Status que indicam depósito aprovado (varia por adquirente)
        $approvedStatuses = ['PAID_OUT', 'COMPLETED', 'PAID', 'APPROVED'];
        
        // Verificar se o status mudou para um status de aprovado
        if ($solicitacoes->wasChanged('status') && in_array($solicitacoes->status, $approvedStatuses)) {
            Log::info('[OBSERVER] Status mudou para aprovado - enviando notificação', [
                'solicitacao_id' => $solicitacoes->id,
                'user_id' => $solicitacoes->user_id,
                'status' => $solicitacoes->status,
                'amount' => $solicitacoes->deposito_liquido
            ]);
            $this->sendDepositNotification($solicitacoes);
        } else {
            Log::info('[OBSERVER] Condições não atendidas para envio de notificação', [
                'wasChanged' => $solicitacoes->wasChanged('status'),
                'status' => $solicitacoes->status,
                'is_approved' => in_array($solicitacoes->status, $approvedStatuses)
            ]);
        }
    }

    /**
     * Handle the Solicitacoes "deleted" event.
     */
    public function deleted(Solicitacoes $solicitacoes): void
    {
        //
    }

    /**
     * Handle the Solicitacoes "restored" event.
     */
    public function restored(Solicitacoes $solicitacoes): void
    {
        //
    }

    /**
     * Handle the Solicitacoes "force deleted" event.
     */
    public function forceDeleted(Solicitacoes $solicitacoes): void
    {
        //
    }

    /**
     * Enviar notificação de depósito aprovado
     */
    private function sendDepositNotification(Solicitacoes $solicitacao): void
    {
        try {
            Log::info('[OBSERVER] Enviando notificação de depósito aprovado', [
                'solicitacao_id' => $solicitacao->id,
                'user_id' => $solicitacao->user_id,
                'amount' => $solicitacao->deposito_liquido,
                'transaction_id' => $solicitacao->idTransaction ?? $solicitacao->id
            ]);

            $result = $this->pushService->sendDepositNotification(
                $solicitacao->user_id,
                $solicitacao->deposito_liquido,
                $solicitacao->idTransaction ?? $solicitacao->id
            );

            Log::info('[OBSERVER] Resultado do envio da notificação', [
                'solicitacao_id' => $solicitacao->id,
                'success' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('[OBSERVER] Erro ao enviar notificação de depósito via Observer', [
                'solicitacao_id' => $solicitacao->id,
                'user_id' => $solicitacao->user_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
