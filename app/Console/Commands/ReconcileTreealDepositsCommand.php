<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileTreealDepositsJob;
use App\Services\Treeal\TreealDepositReconciler;
use Illuminate\Console\Command;

class ReconcileTreealDepositsCommand extends Command
{
    protected $signature = 'treeal:reconcile-deposits';

    protected $description = 'Reconcilia depósitos PIX TREEAL pendentes consultando GET /cob/{txid}';

    public function handle(ReconcileTreealDepositsJob $job, TreealDepositReconciler $reconciler): int
    {
        $job->handle($reconciler);

        return self::SUCCESS;
    }
}
