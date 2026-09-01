<?php

namespace App\Jobs;

use App\Models\Solicitacoes;
use App\Services\FinancialService;
use App\Services\Paytler\PaytlerPixAcquirerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reconcilia ESTORNOS (devoluções) PIX via PAYTLER presos em REFUND_PROCESSING.
 *
 * A devolução (reverse-pix-in) é assíncrona: createRefund retorna NEW e processa em
 * segundos, podendo FALHAR. Só finalizamos o estorno (marca REFUNDED + decrementa saldo)
 * quando a Paytler confirma REFUNDED — evita tirar do saldo antes da confirmação e perder
 * dinheiro se a devolução falhar. FAILED -> reverte o depósito pra PAID_OUT.
 */
class ReconcilePaytlerRefundsJob implements ShouldQueue
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

        $pending = Solicitacoes::where('status', 'REFUND_PROCESSING')
            ->where('executor_ordem', 'paytler')
            ->whereNotNull('refund_provider_id')
            ->where('refund_provider_id', '!=', '')
            ->oldest('updated_at')
            ->limit(50)
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        $financial = app(FinancialService::class);

        foreach ($pending as $deposit) {
            try {
                $result = $paytler->getRefundStatus((string) $deposit->refund_provider_id);
                if (! ($result['success'] ?? false)) {
                    continue; // devolução ainda não indexada; tenta no próximo ciclo
                }

                $status = $result['status'] ?? 'PROCESSING';

                if ($status === 'REFUNDED') {
                    $financial->completeAsyncRefund($deposit);
                    Log::info('[PAYTLER][RECONCILE] Estorno confirmado', [
                        'deposit_id' => $deposit->id,
                        'refund_id' => $deposit->refund_provider_id,
                    ]);
                } elseif ($status === 'FAILED') {
                    $financial->revertFailedAsyncRefund($deposit);
                    Log::warning('[PAYTLER][RECONCILE] Estorno FALHOU na adquirente; depósito revertido pra PAID_OUT', [
                        'deposit_id' => $deposit->id,
                        'refund_id' => $deposit->refund_provider_id,
                        'provider_status' => $result['provider_status'] ?? null,
                    ]);
                }
                // PROCESSING: segue em REFUND_PROCESSING, reavalia no próximo ciclo.

                usleep(200_000);
            } catch (\Throwable $e) {
                Log::error('[PAYTLER][RECONCILE] Erro ao reconciliar estorno', [
                    'deposit_id' => $deposit->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
