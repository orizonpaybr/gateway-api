<?php

namespace App\Observers;

use App\Models\SolicitacoesCashOut;
use App\Services\PushNotificationService;
use App\Services\NotificationPreferenceService;
use Illuminate\Support\Facades\Log;

class SolicitacoesCashOutObserver
{
    private const APPROVED_STATUSES = ['PAID_OUT', 'COMPLETED', 'PAID', 'APPROVED'];
    
    private $pushService;
    private $preferenceService;

    public function __construct(
        PushNotificationService $pushService,
        NotificationPreferenceService $preferenceService
    ) {
        $this->pushService = $pushService;
        $this->preferenceService = $preferenceService;
    }

    /**
     * Handle the SolicitacoesCashOut "created" event.
     */
    public function created(SolicitacoesCashOut $solicitacoesCashOut): void
    {
        // Enviar notificação quando um saque é criado com status aprovado
        if (in_array($solicitacoesCashOut->status, self::APPROVED_STATUSES)) {
            $this->sendWithdrawNotification($solicitacoesCashOut);
        }
    }

    /**
     * Handle the SolicitacoesCashOut "updated" event.
     */
    public function updated(SolicitacoesCashOut $solicitacoesCashOut): void
    {
        // Verificar se o status mudou para um status de aprovado
        if ($solicitacoesCashOut->wasChanged('status') && 
            in_array($solicitacoesCashOut->status, self::APPROVED_STATUSES)) {
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
            // Verificar se o usuário quer receber notificações de saque
            if (!$this->preferenceService->shouldNotify($solicitacao->user_id, 'withdrawal')) {
                Log::info('[OBSERVER] Notificação de saque bloqueada por preferência do usuário', [
                    'solicitacao_id' => $solicitacao->id,
                    'user_id' => $solicitacao->user_id
                ]);
                return;
            }

            Log::info('[OBSERVER] Enviando notificação de saque aprovado', [
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
            Log::error('[OBSERVER] Erro ao enviar notificação de saque via Observer', [
                'solicitacao_id' => $solicitacao->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
