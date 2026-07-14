<?php

namespace App\Jobs;

use App\Models\SolicitacoesCashOut;
use App\Services\FluxPayments\FluxPaymentsCashOutOutcomeService;
use App\Services\FluxPayments\FluxPaymentsPixAcquirerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconcilia saques PIX FluxPayments em PROCESSING/PENDING via GET pix-out.
 */
class ReconcileFluxPaymentsPayoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(): void
    {
        $flux = app(FluxPaymentsPixAcquirerService::class);

        if (! $flux->isActive()) {
            return;
        }

        $pendingPayouts = SolicitacoesCashOut::whereIn('status', ['PROCESSING', 'PENDING'])
            ->where('executor_ordem', 'fluxpayments')
            ->whereNotNull('idTransaction')
            ->where('idTransaction', '!=', '')
            ->oldest('updated_at')
            ->limit(50)
            ->get();

        if ($pendingPayouts->isEmpty()) {
            return;
        }

        Log::info('[FLUXPAYMENTS][RECONCILE] Iniciando reconciliação de payouts', [
            'total' => $pendingPayouts->count(),
        ]);

        $outcomeService = app(FluxPaymentsCashOutOutcomeService::class);
        $updated = 0;

        foreach ($pendingPayouts as $payout) {
            try {
                $e2e = trim((string) ($payout->end_to_end ?? ''));
                $result = $flux->getPayoutStatus(
                    (string) $payout->idTransaction,
                    $e2e !== '' ? $e2e : null
                );

                if (! ($result['success'] ?? false)) {
                    continue;
                }

                $newStatus = $result['status'];
                $raw = is_array($result['raw'] ?? null) ? $result['raw'] : [];
                $e2eResolved = isset($raw['endToEndId']) && is_string($raw['endToEndId']) && $raw['endToEndId'] !== ''
                    ? $raw['endToEndId']
                    : null;
                $paidAt = isset($raw['paidAt']) && is_string($raw['paidAt']) ? $raw['paidAt'] : null;

                if (in_array($newStatus, ['PROCESSING', 'PENDING'], true)) {
                    if ($e2eResolved !== null) {
                        DB::transaction(function () use ($payout, $e2eResolved) {
                            $locked = SolicitacoesCashOut::where('id', $payout->id)
                                ->lockForUpdate()
                                ->first();

                            if (! $locked || ! in_array($locked->status, ['PROCESSING', 'PENDING'], true)) {
                                return;
                            }

                            if (trim((string) ($locked->end_to_end ?? '')) === '') {
                                $locked->update(['end_to_end' => $e2eResolved]);
                            }
                        });
                    }

                    continue;
                }

                if (! in_array($newStatus, ['COMPLETED', 'FAILED', 'CANCELLED', 'REFUNDED'], true)) {
                    continue;
                }

                if ($outcomeService->applyFinalStatusIfNeeded($payout, $newStatus, $raw, $e2eResolved, $paidAt)) {
                    $updated++;
                }
            } catch (\Throwable $e) {
                Log::error('[FLUXPAYMENTS][RECONCILE] Erro ao reconciliar payout', [
                    'payout_id' => $payout->id,
                    'transaction_id' => $payout->idTransaction,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[FLUXPAYMENTS][RECONCILE] Reconciliação finalizada', [
            'total' => $pendingPayouts->count(),
            'updated' => $updated,
        ]);
    }
}
