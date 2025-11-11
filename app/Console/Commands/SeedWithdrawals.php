<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SeedWithdrawals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:seed-withdrawals';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Insere mocks de saques e ajusta limite de saque automático para R$ 5.000,00';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = base_path('database/seed-test-withdrawals.sql');
        if (!File::exists($path)) {
            $this->error('Arquivo SQL não encontrado: ' . $path);
            return self::FAILURE;
        }

        try {
            $sql = File::get($path);
            DB::unprepared($sql);
            $this->info('Mocks de saques inseridos e configuração aplicada com sucesso.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Erro ao executar seed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}


