<?php

namespace App\Jobs;

use App\Models\Solicitacoes;
use App\Services\Treeal\TreealDepositReconciler;
use App\Services\Treeal\TreealPixAcquirerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReconcileTreealDepositsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(TreealDepositReconciler $reconciler): void
    {
        $treeal = app(TreealPixAcquirerService::class);
        if (! $treeal->isActive()) {
            return;
        }

        $pendingDeposits = Solicitacoes::where('status', 'WAITING_FOR_APPROVAL')
            ->where('executor_ordem', 'treeal')
            ->whereNotNull('idTransaction')
            ->where('idTransaction', '!=', '')
            ->oldest('updated_at')
            ->limit(50)
            ->get();

        if ($pendingDeposits->isEmpty()) {
            return;
        }

        Log::info('[TREEAL][RECONCILE] Iniciando reconciliação de depósitos', [
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
                Log::error('[TREEAL][RECONCILE] Erro ao reconciliar depósito', [
                    'deposit_id' => $deposit->id,
                    'transaction_id' => $deposit->idTransaction,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[TREEAL][RECONCILE] Reconciliação concluída', [
            'checked' => $pendingDeposits->count(),
            'reconciled' => $reconciled,
        ]);
    }
}
