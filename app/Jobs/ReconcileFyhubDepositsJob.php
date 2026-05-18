<?php

namespace App\Jobs;

use App\Models\Solicitacoes;
use App\Services\Fyhub\FyhubDepositReconciler;
use App\Services\Fyhub\FyhubPixAcquirerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reconcilia depósitos PIX FYHUB em WAITING_FOR_APPROVAL consultando GET /cob/{txid}.
 * Cobre o caso em que o pagamento liquidou na FYHUB mas o webhook não chegou à Coratri.
 */
class ReconcileFyhubDepositsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(FyhubDepositReconciler $reconciler): void
    {
        $fyhub = app(FyhubPixAcquirerService::class);
        if (! $fyhub->isActive()) {
            return;
        }

        $pendingDeposits = Solicitacoes::where('status', 'WAITING_FOR_APPROVAL')
            ->where('executor_ordem', 'fyhub')
            ->whereNotNull('idTransaction')
            ->where('idTransaction', '!=', '')
            ->oldest('updated_at')
            ->limit(50)
            ->get();

        if ($pendingDeposits->isEmpty()) {
            return;
        }

        Log::info('[FYHUB][RECONCILE] Iniciando reconciliação de depósitos', [
            'total' => $pendingDeposits->count(),
        ]);

        $reconciled = 0;

        foreach ($pendingDeposits as $deposit) {
            try {
                if ($reconciler->reconcileIfPaid($deposit)) {
                    $reconciled++;
                }

                usleep(200_000);
            } catch (\Throwable $e) {
                Log::error('[FYHUB][RECONCILE] Erro ao reconciliar depósito', [
                    'deposit_id' => $deposit->id,
                    'transaction_id' => $deposit->idTransaction,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[FYHUB][RECONCILE] Reconciliação concluída', [
            'checked' => $pendingDeposits->count(),
            'reconciled' => $reconciled,
        ]);
    }
}
