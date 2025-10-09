<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;

class ViewBalanceLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'balance:view-logs {--user= : Filtrar por user_id} {--operation= : Filtrar por operação} {--lines=50 : Número de linhas para exibir} {--today : Mostrar apenas logs de hoje}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Visualiza logs de operações de saldo';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $logFile = storage_path('logs/analisarsaque.log');
        
        if (!file_exists($logFile)) {
            $this->error('Arquivo de log não encontrado: ' . $logFile);
            return 1;
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $filteredLines = [];
        $userFilter = $this->option('user');
        $operationFilter = $this->option('operation');
        $maxLines = (int) $this->option('lines');
        $todayOnly = $this->option('today');

        foreach ($lines as $line) {
            // Filtrar por data (hoje)
            if ($todayOnly) {
                if (!str_contains($line, Carbon::today()->format('Y-m-d'))) {
                    continue;
                }
            }

            // Filtrar por usuário
            if ($userFilter && !str_contains($line, "User: {$userFilter}")) {
                continue;
            }

            // Filtrar por operação
            if ($operationFilter && !str_contains($line, $operationFilter)) {
                continue;
            }

            $filteredLines[] = $line;
        }

        // Pegar as últimas N linhas
        $filteredLines = array_slice($filteredLines, -$maxLines);

        if (empty($filteredLines)) {
            $this->info('Nenhum log encontrado com os filtros aplicados.');
            return 0;
        }

        $this->info('=== LOGS DE OPERAÇÕES DE SALDO ===');
        $this->line('');

        foreach ($filteredLines as $line) {
            // Colorir diferentes tipos de operação
            if (str_contains($line, 'INCREMENT')) {
                $this->line('<fg=green>' . $line . '</>');
            } elseif (str_contains($line, 'DECREMENT')) {
                $this->line('<fg=red>' . $line . '</>');
            } elseif (str_contains($line, 'SAQUE_')) {
                $this->line('<fg=yellow>' . $line . '</>');
            } elseif (str_contains($line, 'DEPOSIT_')) {
                $this->line('<fg=blue>' . $line . '</>');
            } elseif (str_contains($line, 'BALANCE_CALCULATION')) {
                $this->line('<fg=cyan>' . $line . '</>');
            } else {
                $this->line($line);
            }
        }

        $this->line('');
        $this->info('Total de logs exibidos: ' . count($filteredLines));
        $this->info('Arquivo: ' . $logFile);

        return 0;
    }
}

