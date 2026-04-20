<?php

namespace App\Jobs;

use App\Helpers\Helper;
use App\Models\SolicitacoesCashOut;
use App\Services\ClientWebhookPayloadBuilder;
use App\Services\WithdrawalFailureRefundService;
use App\Services\Simpay\SimpayPixAcquirerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconcilia saques PIX via SIMPAY que ficaram em PROCESSING.
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

        $pendingPayouts = SolicitacoesCashOut::where('status', 'PROCESSING')
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

        $updated = 0;

        foreach ($pendingPayouts as $payout) {
            try {
                $result = $simpay->getPayoutStatus($payout->idTransaction);

                if (! ($result['success'] ?? false)) {
                    continue;
                }

                $newStatus = $result['status'];

                if ($newStatus === 'PROCESSING') {
                    continue;
                }

                $raw = $result['raw'] ?? [];
                $e2e = $raw['endToEndId'] ?? null;

                DB::transaction(function () use ($payout, $newStatus, $e2e) {
                    $locked = SolicitacoesCashOut::where('id', $payout->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $locked || $locked->status !== 'PROCESSING') {
                        return;
                    }

                    $previousStatus = $locked->status;

                    $updateData = ['status' => $newStatus];
                    if ($e2e !== null && $e2e !== '') {
                        $updateData['end_to_end'] = $e2e;
                    }

                    $locked->update($updateData);

                    WithdrawalFailureRefundService::creditBackIfApplicable(
                        $locked->fresh(),
                        $previousStatus,
                        $newStatus
                    );
                });

                $updated++;

                Log::info('[SIMPAY][RECONCILE] Status atualizado', [
                    'saque_id' => $payout->id,
                    'transaction_id' => $payout->idTransaction,
                    'old_status' => 'PROCESSING',
                    'new_status' => $newStatus,
                ]);

                if (! empty($payout->callback) && $payout->callback !== 'web') {
                    $fresh = SolicitacoesCashOut::find($payout->id);
                    if ($fresh) {
                        ClientWebhookDispatchJob::dispatch(
                            $payout->callback,
                            $payout->idTransaction,
                            $newStatus,
                            (float) $payout->amount,
                            now()->toIso8601String(),
                            ClientWebhookPayloadBuilder::extraForCashOut($fresh),
                            $newStatus === 'COMPLETED'
                                ? 'Saque PIX concluído com sucesso.'
                                : 'Saque PIX cancelado.'
                        );
                    }
                }

                Helper::calculaSaldoLiquido($payout->user_id);
                app(\App\Services\PaymentProcessingService::class)
                    ->invalidateCachesAfterPayment($payout->user_id);

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
