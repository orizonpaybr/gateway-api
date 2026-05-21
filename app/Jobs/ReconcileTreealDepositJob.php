<?php

namespace App\Jobs;

use App\Models\Solicitacoes;
use App\Services\Treeal\TreealDepositReconciler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReconcileTreealDepositJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public int $depositId,
    ) {}

    public function handle(TreealDepositReconciler $reconciler): void
    {
        $deposit = Solicitacoes::find($this->depositId);
        if ($deposit === null) {
            return;
        }

        if ($deposit->executor_ordem !== 'treeal' || $deposit->status !== 'WAITING_FOR_APPROVAL') {
            return;
        }

        try {
            if ($reconciler->reconcileIfPaid($deposit)) {
                Log::info('[TREEAL][RECONCILE] Depósito liquidado (job unitário)', [
                    'deposit_id' => $deposit->id,
                    'txid' => $deposit->idTransaction,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[TREEAL][RECONCILE] Erro no job unitário', [
                'deposit_id' => $this->depositId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
