<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileFyhubDepositsJob;
use App\Services\Fyhub\FyhubDepositReconciler;
use Illuminate\Console\Command;

/**
 * Executa reconciliação FYHUB de forma síncrona (cron), sem depender de queue worker.
 */
class ReconcileFyhubDepositsCommand extends Command
{
    protected $signature = 'fyhub:reconcile-deposits';

    protected $description = 'Reconcilia depósitos PIX FYHUB pendentes consultando GET /cob/{txid}';

    public function handle(ReconcileFyhubDepositsJob $job, FyhubDepositReconciler $reconciler): int
    {
        $job->handle($reconciler);

        return self::SUCCESS;
    }
}
