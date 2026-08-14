<?php

namespace App\Jobs;

use App\Models\SolicitacoesCashOut;
use App\Services\Fyhub\FyhubCashOutOutcomeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reconcilia um único saque FYHUB logo após o createPayout, via poll do getPayoutStatus.
 *
 * A FYHUB cancela falhas (ex.: saldo insuficiente na conta master) SEM enviar webhook,
 * e o status endpoint leva alguns segundos para refletir o CANCELED — o poll síncrono
 * do request (~1,2s) não pega. Este job re-consulta com delays curtos e aplica o terminal
 * (estorno auditado + postback ao cliente) em segundos, sem esperar o reconciler agendado.
 */
class ReconcileFyhubPayoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public int $cashOutId,
    ) {}

    public function handle(FyhubCashOutOutcomeService $outcome): void
    {
        $payout = SolicitacoesCashOut::find($this->cashOutId);
        if ($payout === null) {
            return;
        }

        if ($payout->executor_ordem !== 'fyhub' || ! in_array($payout->status, ['PENDING', 'PROCESSING'], true)) {
            return;
        }

        try {
            $status = $outcome->pollApiAndApplyIfTerminal($payout);
            if ($status !== null) {
                Log::info('[FYHUB][PAYOUT_RECONCILE] Saque resolvido (job unitário)', [
                    'cash_out_id' => $payout->id,
                    'status' => $status,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[FYHUB][PAYOUT_RECONCILE] Erro no job unitário', [
                'cash_out_id' => $this->cashOutId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
