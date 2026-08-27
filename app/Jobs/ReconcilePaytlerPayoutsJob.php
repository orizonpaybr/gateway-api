<?php

namespace App\Jobs;

use App\Models\SolicitacoesCashOut;
use App\Services\Paytler\PaytlerCashOutOutcomeService;
use App\Services\Paytler\PaytlerPixAcquirerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconcilia saques PIX via PAYTLER presos em PROCESSING/PENDING.
 * Rede de segurança da trava "um saque por vez". Espelha ReconcileSimpayPayoutsJob.
 */
class ReconcilePaytlerPayoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function handle(): void
    {
        $paytler = app(PaytlerPixAcquirerService::class);
        if (! $paytler->isActive()) {
            return;
        }

        $pending = SolicitacoesCashOut::whereIn('status', ['PROCESSING', 'PENDING'])
            ->where('executor_ordem', 'paytler')
            ->whereNotNull('idTransaction')
            ->where('idTransaction', '!=', '')
            ->oldest('updated_at')
            ->limit(50)
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        $outcomeService = app(PaytlerCashOutOutcomeService::class);

        foreach ($pending as $payout) {
            try {
                $e2e = trim((string) ($payout->end_to_end ?? ''));
                $result = $paytler->getPayoutStatus($payout->idTransaction, $e2e !== '' ? $e2e : null);
                if (! ($result['success'] ?? false)) {
                    continue;
                }

                $newStatus = $result['status'];
                $raw = is_array($result['raw'] ?? null) ? $result['raw'] : [];
                $e2eNew = isset($raw['endToEndId']) && is_string($raw['endToEndId']) && $raw['endToEndId'] !== ''
                    ? $raw['endToEndId']
                    : null;

                if (in_array($newStatus, ['PROCESSING', 'PENDING'], true)) {
                    DB::transaction(function () use ($payout, $newStatus, $e2eNew) {
                        $locked = SolicitacoesCashOut::where('id', $payout->id)->lockForUpdate()->first();
                        if (! $locked || ! in_array($locked->status, ['PROCESSING', 'PENDING'], true)) {
                            return;
                        }
                        $data = [];
                        if ($locked->status !== $newStatus) {
                            $data['status'] = $newStatus;
                        }
                        if ($e2eNew !== null && ($locked->end_to_end === null || $locked->end_to_end === '')) {
                            $data['end_to_end'] = $e2eNew;
                        }
                        if ($data !== []) {
                            $locked->update($data);
                        }
                    });

                    continue;
                }

                $outcomeService->applyFinalStatusIfNeeded($payout, $newStatus, $raw, $e2eNew, null);
                usleep(200_000);
            } catch (\Throwable $e) {
                Log::error('[PAYTLER][RECONCILE] Erro ao reconciliar payout', [
                    'saque_id' => $payout->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
