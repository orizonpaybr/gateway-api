<?php

namespace App\Observers;

use App\Models\SolicitacoesCashOut;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\Log;

class SolicitacoesCashOutObserver
{
    private $pushService;

    public function __construct(PushNotificationService $pushService)
    {
        $this->pushService = $pushService;
    }

    /**
     * Handle the SolicitacoesCashOut "created" event.
     */
    public function created(SolicitacoesCashOut $solicitacoesCashOut): void
    {
        // Status que indicam saque aprovado (varia por adquirente)
        $approvedStatuses = ['PAID_OUT', 'COMPLETED', 'PAID', 'APPROVED'];
        
        // Enviar notificação quando um saque é criado com status aprovado
        if (in_array($solicitacoesCashOut->status, $approvedStatuses)) {
            $this->sendWithdrawNotification($solicitacoesCashOut);
        }
    }

    /**
     * Handle the SolicitacoesCashOut "updated" event.
     */
    public function updated(SolicitacoesCashOut $solicitacoesCashOut): void
    {
        // Status que indicam saque aprovado (varia por adquirente)
        $approvedStatuses = ['PAID_OUT', 'COMPLETED', 'PAID', 'APPROVED'];
        
        // Verificar se o status mudou para um status de aprovado
        if ($solicitacoesCashOut->wasChanged('status') && 
            in_array($solicitacoesCashOut->status, $approvedStatuses)) {
            $this->sendWithdrawNotification($solicitacoesCashOut);
        }
    }

    /**
     * Handle the SolicitacoesCashOut "deleted" event.
     */
    public function deleted(SolicitacoesCashOut $solicitacoesCashOut): void
    {
        //
    }

    /**
     * Handle the SolicitacoesCashOut "restored" event.
     */
    public function restored(SolicitacoesCashOut $solicitacoesCashOut): void
    {
        //
    }

    /**
     * Handle the SolicitacoesCashOut "force deleted" event.
     */
    public function forceDeleted(SolicitacoesCashOut $solicitacoesCashOut): void
    {
        //
    }

    /**
     * Enviar notificação de saque aprovado
     */
    private function sendWithdrawNotification(SolicitacoesCashOut $solicitacao): void
    {
        try {
            Log::info('Observer: Enviando notificação de saque aprovado', [
                'solicitacao_id' => $solicitacao->id,
                'user_id' => $solicitacao->user_id,
                'amount' => $solicitacao->cash_out_liquido
            ]);

            $this->pushService->sendWithdrawNotification(
                $solicitacao->user_id,
                $solicitacao->cash_out_liquido,
                $solicitacao->idTransaction ?? $solicitacao->id
            );

        } catch (\Exception $e) {
            Log::error('Erro ao enviar notificação de saque via Observer', [
                'solicitacao_id' => $solicitacao->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
