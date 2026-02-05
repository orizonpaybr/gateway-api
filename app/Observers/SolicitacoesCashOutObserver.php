<?php

namespace App\Observers;

use App\Models\SolicitacoesCashOut;
use App\Http\Controllers\Api\QRCodeController;

class SolicitacoesCashOutObserver
{
    /**
     * Handle the SolicitacoesCashOut "created" event.
     */
    public function created(SolicitacoesCashOut $solicitacoesCashOut): void
    {
        // Limpar cache de QR codes quando nova transação é criada
        // CRÍTICO para gateway de pagamento - dados devem ser atualizados imediatamente
        if ($solicitacoesCashOut->user_id) {
            QRCodeController::clearUserCache($solicitacoesCashOut->user_id);
        }
    }

    /**
     * Handle the SolicitacoesCashOut "updated" event.
     */
    public function updated(SolicitacoesCashOut $solicitacoesCashOut): void
    {
        // Limpar cache de QR codes quando transação é atualizada
        // CRÍTICO para gateway de pagamento - dados devem ser atualizados imediatamente
        if ($solicitacoesCashOut->user_id) {
            QRCodeController::clearUserCache($solicitacoesCashOut->user_id);
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

}
