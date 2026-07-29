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
 * Reconcilia saques PIX FluxPayments/Paya55 em PROCESSING/PENDING via GET pix-out.
 * Resolve a nominal por payout (adquirente_ref), não o singleton .env.
 */
class ReconcileFluxPaymentsPayoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(): void
    {
        $pendingPayouts = SolicitacoesCashOut::whereIn('status', ['PROCESSING', 'PENDING'])
            ->where(function ($q) {
                $q->whereIn('executor_ordem', FluxPaymentsPixAcquirerService::FAMILY);
                foreach (FluxPaymentsPixAcquirerService::FAMILY as $family) {
                    $q->orWhere('adquirente_ref', 'like', $family.'%');
                }
            })
            ->whereNotNull('idTransaction')
            ->where('idTransaction', '!=', '')
            ->oldest('updated_at')
            ->limit(50)
            ->get();

        if ($pendingPayouts->isEmpty()) {
            return;
        }

        Log::info('[A55][RECONCILE] Iniciando reconciliação de payouts', [
            'total' => $pendingPayouts->count(),
        ]);

        $outcomeService = app(FluxPaymentsCashOutOutcomeService::class);
        $updated = 0;

        foreach ($pendingPayouts as $payout) {
            try {
                $flux = FluxPaymentsCashOutOutcomeService::resolveAcquirerForPayout($payout);
                if (! $flux->isActive()) {
                    continue;
                }

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
                Log::error('[A55][RECONCILE] Erro ao reconciliar payout', [
                    'payout_id' => $payout->id,
                    'transaction_id' => $payout->idTransaction,
                    'adquirente_ref' => $payout->adquirente_ref,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[A55][RECONCILE] Reconciliação finalizada', [
            'total' => $pendingPayouts->count(),
            'updated' => $updated,
        ]);
    }
}
