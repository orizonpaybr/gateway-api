<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ProcessPendingDeposits extends Command
{
    protected $signature = 'deposits:process-pending';
    protected $description = 'Comando desativado: integração de adquirente PIX removida.';

    public function handle(): int
    {
        $this->warn('Comando desativado temporariamente até integração da nova adquirente PIX.');
        return self::SUCCESS;
    }
}
