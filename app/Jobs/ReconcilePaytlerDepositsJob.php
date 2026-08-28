<?php

namespace App\Jobs;

use App\Models\Solicitacoes;
use App\Services\ClientWebhookPayloadBuilder;
use App\Services\Paytler\PaytlerPixAcquirerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconcilia depósitos PIX (cash in) via PAYTLER presos em WAITING_FOR_APPROVAL.
 * Rede de segurança caso o webhook não chegue. Espelha ReconcileSimpayDepositsJob.
 */
class ReconcilePaytlerDepositsJob implements ShouldQueue
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

        $pending = Solicitacoes::where('status', 'WAITING_FOR_APPROVAL')
            ->where('executor_ordem', 'paytler')
            ->whereNotNull('idTransaction')
            ->where('idTransaction', '!=', '')
            ->oldest('updated_at')
            ->limit(50)
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        foreach ($pending as $deposit) {
            try {
                $result = $paytler->getChargeStatus($deposit->idTransaction);
                if (! ($result['success'] ?? false)) {
                    continue;
                }

                $newStatus = $result['status'];
                if ($newStatus === 'WAITING_FOR_APPROVAL') {
                    continue;
                }

                $raw = $result['raw'] ?? [];
                $e2e = isset($raw['endToEndId']) && $raw['endToEndId'] !== '' ? (string) $raw['endToEndId'] : null;
                $txid = trim((string) ($raw['transaction_id'] ?? ''));
                $paymentDate = $raw['payment_date'] ?? null;

                $effectiveStatus = $newStatus;

                if ($newStatus === 'PAID_OUT') {
                    // Crédito com dedup por pagamento (txid): não credita 2x o mesmo
                    // pagamento (Paytler amarra vários charges a 1 txid). Ver PaytlerCashInService.
                    $outcome = app(\App\Services\Paytler\PaytlerCashInService::class)
                        ->creditIfNotDuplicate($deposit, $txid, $e2e);
                    if ($outcome === 'noop') {
                        continue;
                    }
                    if ($outcome === 'duplicate') {
                        // Charge redundante anulado (sem crédito) — reporta CANCELLED ao cliente.
                        $effectiveStatus = 'CANCELLED';
                    }
                } else {
                    // Status terminal negativo — atualiza sem creditar.
                    DB::transaction(function () use ($deposit, $newStatus, $e2e) {
                        $locked = Solicitacoes::where('id', $deposit->id)->lockForUpdate()->first();
                        if (! $locked || $locked->status !== 'WAITING_FOR_APPROVAL') {
                            return;
                        }
                        $data = ['status' => $newStatus];
                        if ($e2e !== null && $e2e !== '') {
                            $data['end_to_end'] = $e2e;
                        }
                        $locked->update($data);
                    });
                }

                if (! empty($deposit->callback) && $deposit->callback !== 'web') {
                    $fresh = Solicitacoes::find($deposit->id);
                    if ($fresh) {
                        ClientWebhookDispatchJob::send(
                            $deposit->callback,
                            $deposit->idTransaction,
                            $effectiveStatus,
                            (float) $deposit->amount,
                            $paymentDate ?? now()->toIso8601String(),
                            ClientWebhookPayloadBuilder::extraForDeposit($fresh),
                            $effectiveStatus === 'PAID_OUT'
                                ? 'Depósito PIX recebido com sucesso.'
                                : 'Depósito PIX cancelado/expirado.'
                        );
                    }
                }

                usleep(200_000);
            } catch (\Throwable $e) {
                Log::error('[PAYTLER][RECONCILE_DEPOSIT] Erro ao reconciliar depósito', [
                    'deposit_id' => $deposit->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
