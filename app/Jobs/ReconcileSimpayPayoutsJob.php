<?php

namespace App\Jobs;

use App\Models\SolicitacoesCashOut;
use App\Services\Simpay\SimpayCashOutOutcomeService;
use App\Services\Simpay\SimpayPixAcquirerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconcilia saques PIX via SIMPAY que ficaram em PROCESSING ou PENDING.
 *
 * A API SIMPAY frequentemente retorna PROCESSING na chamada síncrona e
 * o status final (SUCCESS/CANCELED) só fica disponível depois.
 * Este job consulta periodicamente a API para atualizar registros pendentes.
 */
class ReconcileSimpayPayoutsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(): void
    {
        $simpay = app(SimpayPixAcquirerService::class);

        if (! $simpay->isActive()) {
            return;
        }

        $pendingPayouts = SolicitacoesCashOut::whereIn('status', ['PROCESSING', 'PENDING'])
            ->where('executor_ordem', 'simpay')
            ->whereNotNull('idTransaction')
            ->where('idTransaction', '!=', '')
            ->oldest('updated_at')
            ->limit(50)
            ->get();

        if ($pendingPayouts->isEmpty()) {
            return;
        }

        Log::info('[SIMPAY][RECONCILE] Iniciando reconciliação', [
            'total' => $pendingPayouts->count(),
        ]);

        $outcomeService = app(SimpayCashOutOutcomeService::class);
        $updated = 0;

        foreach ($pendingPayouts as $payout) {
            try {
                $result = $simpay->getPayoutStatus($payout->idTransaction);

                if (! ($result['success'] ?? false)) {
                    continue;
                }

                $newStatus = $result['status'];
                $raw = is_array($result['raw'] ?? null) ? $result['raw'] : [];
                $e2e = isset($raw['endToEndId']) && is_string($raw['endToEndId']) && $raw['endToEndId'] !== ''
                    ? $raw['endToEndId']
                    : null;

                if (in_array($newStatus, ['PROCESSING', 'PENDING'], true)) {
                    DB::transaction(function () use ($payout, $newStatus, $e2e) {
                        $locked = SolicitacoesCashOut::where('id', $payout->id)
                            ->lockForUpdate()
                            ->first();

                        if (! $locked || ! in_array($locked->status, ['PROCESSING', 'PENDING'], true)) {
                            return;
                        }

                        $updateData = [];
                        if ($locked->status !== $newStatus) {
                            $updateData['status'] = $newStatus;
                        }
                        if ($e2e !== null && ($locked->end_to_end === null || $locked->end_to_end === '')) {
                            $updateData['end_to_end'] = $e2e;
                        }

                        if ($updateData !== []) {
                            $locked->update($updateData);
                        }
                    });

                    continue;
                }

                if ($outcomeService->applyFinalStatusIfNeeded($payout, $newStatus, $raw, $e2e, null)) {
                    $updated++;

                    Log::info('[SIMPAY][RECONCILE] Status atualizado', [
                        'saque_id' => $payout->id,
                        'transaction_id' => $payout->idTransaction,
                        'old_status' => 'PROCESSING|PENDING',
                        'new_status' => $newStatus,
                    ]);
                }

                usleep(200_000);
            } catch (\Throwable $e) {
                Log::error('[SIMPAY][RECONCILE] Erro ao reconciliar payout', [
                    'saque_id' => $payout->id,
                    'transaction_id' => $payout->idTransaction,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[SIMPAY][RECONCILE] Reconciliação concluída', [
            'checked' => $pendingPayouts->count(),
            'updated' => $updated,
        ]);
    }
}
