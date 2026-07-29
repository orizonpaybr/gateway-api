<?php

namespace App\Jobs;

use App\Models\Solicitacoes;
use App\Services\FluxPayments\FluxPaymentsDepositReconciler;
use App\Services\FluxPayments\FluxPaymentsPixAcquirerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reconcilia um depósito FluxPayments/Paya55 (fallback se o postback atrasar/falhar).
 */
class ReconcileFluxPaymentsDepositJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public int $depositId,
    ) {}

    public function handle(FluxPaymentsDepositReconciler $reconciler): void
    {
        $deposit = Solicitacoes::find($this->depositId);
        if ($deposit === null) {
            return;
        }

        $provider = strtolower(trim((string) ($deposit->executor_ordem ?? '')));
        if (! in_array($provider, FluxPaymentsPixAcquirerService::FAMILY, true) || $deposit->status !== 'WAITING_FOR_APPROVAL') {
            return;
        }

        $tag = '['.strtoupper($provider).'][RECONCILE]';

        try {
            if ($reconciler->reconcileIfPaid($deposit)) {
                Log::info($tag.' Depósito liquidado (job unitário)', [
                    'deposit_id' => $deposit->id,
                    'txid' => $deposit->idTransaction,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[A55][RECONCILE] Erro no job unitário', [
                'deposit_id' => $this->depositId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
