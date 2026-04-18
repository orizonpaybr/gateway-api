<?php

namespace App\Jobs;

use App\Models\Solicitacoes;
use App\Services\PaymentProcessingService;
use App\Services\Simpay\SimpayPixAcquirerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconcilia depósitos PIX (cash in) via SIMPAY que ficaram em WAITING_FOR_APPROVAL.
 *
 * A cobrança PIX pode levar tempo até o cliente efetuar o pagamento.
 * Este job consulta periodicamente a API para detectar cobranças pagas
 * ou expiradas/canceladas e atualizar os registros locais.
 */
class ReconcileSimpayDepositsJob implements ShouldQueue
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

        $pendingDeposits = Solicitacoes::where('status', 'WAITING_FOR_APPROVAL')
            ->where('executor_ordem', 'simpay')
            ->whereNotNull('idTransaction')
            ->where('idTransaction', '!=', '')
            ->oldest('updated_at')
            ->limit(50)
            ->get();

        if ($pendingDeposits->isEmpty()) {
            return;
        }

        Log::info('[SIMPAY][RECONCILE_DEPOSIT] Iniciando reconciliação de depósitos', [
            'total' => $pendingDeposits->count(),
        ]);

        $updated = 0;

        foreach ($pendingDeposits as $deposit) {
            try {
                $result = $simpay->getChargeStatus($deposit->idTransaction);

                if (! ($result['success'] ?? false)) {
                    continue;
                }

                $newStatus = $result['status'];

                if ($newStatus === 'WAITING_FOR_APPROVAL') {
                    continue;
                }

                $raw = $result['raw'] ?? [];
                $e2e = $raw['endToEndId'] ?? null;
                $paymentDate = $raw['payment_date'] ?? null;

                DB::transaction(function () use ($deposit, $newStatus, $e2e) {
                    $locked = Solicitacoes::where('id', $deposit->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $locked || $locked->status !== 'WAITING_FOR_APPROVAL') {
                        return;
                    }

                    $updateData = ['status' => $newStatus];
                    if ($e2e !== null && $e2e !== '') {
                        $updateData['end_to_end'] = $e2e;
                    }

                    $locked->update($updateData);
                });

                $updated++;

                Log::info('[SIMPAY][RECONCILE_DEPOSIT] Status atualizado', [
                    'deposit_id' => $deposit->id,
                    'transaction_id' => $deposit->idTransaction,
                    'old_status' => 'WAITING_FOR_APPROVAL',
                    'new_status' => $newStatus,
                    'payment_date' => $paymentDate,
                ]);

                if ($newStatus === 'PAID_OUT') {
                    $fresh = Solicitacoes::find($deposit->id);
                    if ($fresh) {
                        try {
                            app(PaymentProcessingService::class)->processPaymentReceived($fresh);
                        } catch (\Throwable $e) {
                            Log::error('[SIMPAY][RECONCILE_DEPOSIT] Falha ao creditar depósito', [
                                'deposit_id' => $deposit->id,
                                'transaction_id' => $deposit->idTransaction,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }

                if (! empty($deposit->callback) && $deposit->callback !== 'web') {
                    ClientWebhookDispatchJob::dispatch(
                        $deposit->callback,
                        $deposit->idTransaction,
                        $newStatus,
                        (float) $deposit->amount,
                        $paymentDate ?? now()->toIso8601String(),
                        ['typeTransaction' => 'PIX_IN'],
                        $newStatus === 'PAID_OUT'
                            ? 'Depósito PIX recebido com sucesso.'
                            : 'Depósito PIX cancelado/expirado.'
                    );
                }

                usleep(200_000);

            } catch (\Throwable $e) {
                Log::error('[SIMPAY][RECONCILE_DEPOSIT] Erro ao reconciliar depósito', [
                    'deposit_id' => $deposit->id,
                    'transaction_id' => $deposit->idTransaction,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[SIMPAY][RECONCILE_DEPOSIT] Reconciliação concluída', [
            'checked' => $pendingDeposits->count(),
            'updated' => $updated,
        ]);
    }
}
