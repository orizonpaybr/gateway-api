<?php

namespace App\Jobs;

use App\Models\Solicitacoes;
use App\Services\Fyhub\FyhubDepositReconciler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reconcilia um único depósito FYHUB (ex.: após criar cob via API).
 * Disparado com delay para capturar pagamento sem esperar só o cron.
 */
class ReconcileFyhubDepositJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public int $depositId,
    ) {}

    public function handle(FyhubDepositReconciler $reconciler): void
    {
        $deposit = Solicitacoes::find($this->depositId);
        if ($deposit === null) {
            return;
        }

        if ($deposit->executor_ordem !== 'fyhub' || $deposit->status !== 'WAITING_FOR_APPROVAL') {
            return;
        }

        try {
            if ($reconciler->reconcileIfPaid($deposit)) {
                Log::info('[FYHUB][RECONCILE] Depósito liquidado (job unitário)', [
                    'deposit_id' => $deposit->id,
                    'txid' => $deposit->idTransaction,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[FYHUB][RECONCILE] Erro no job unitário', [
                'deposit_id' => $this->depositId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
