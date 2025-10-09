<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\BalanceLogHelper;

class CleanBalanceLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'balance:clean-logs {--days=30 : Número de dias para manter os logs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Limpa logs antigos de operações de saldo';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $days = $this->option('days');
        
        $this->info("Limpando logs de saldo com mais de {$days} dias...");
        
        try {
            BalanceLogHelper::cleanOldLogs();
            $this->info('Logs antigos removidos com sucesso!');
            return 0;
        } catch (\Exception $e) {
            $this->error('Erro ao limpar logs: ' . $e->getMessage());
            return 1;
        }
    }
}

