<?php

namespace App\Observers;

use App\Models\Solicitacoes;
use App\Http\Controllers\Api\QRCodeController;
use Illuminate\Support\Facades\Log;

class SolicitacoesObserver
{
    private const APPROVED_STATUSES = ['PAID_OUT', 'COMPLETED', 'PAID', 'APPROVED']; // usado no log em updated()

    /**
     * Handle the Solicitacoes "created" event.
     */
    public function created(Solicitacoes $solicitacoes): void
    {
        // Limpar cache de QR codes quando nova transação é criada
        // CRÍTICO para gateway de pagamento - dados devem ser atualizados imediatamente
        if ($solicitacoes->user_id) {
            QRCodeController::clearUserCache($solicitacoes->user_id);
        }
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
        
        // Limpar cache de QR codes quando transação é atualizada
        // CRÍTICO para gateway de pagamento - dados devem ser atualizados imediatamente
        if ($solicitacoes->user_id) {
            QRCodeController::clearUserCache($solicitacoes->user_id);
        }
        
        // Verificar se o status mudou para um status de aprovado (apenas log)
        if ($solicitacoes->wasChanged('status') && in_array($solicitacoes->status, self::APPROVED_STATUSES)) {
            Log::info('[OBSERVER] Status mudou para aprovado', [
                'solicitacao_id' => $solicitacoes->id,
                'user_id' => $solicitacoes->user_id,
                'status' => $solicitacoes->status,
                'amount' => $solicitacoes->deposito_liquido
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

}
