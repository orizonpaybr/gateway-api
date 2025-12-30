<?php

namespace Tests\Feature\Helpers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class SetupTestDatabase
{
    /**
     * Garante que o banco de teste está configurado com todas as migrations
     */
    public static function ensureMigrations(): void
    {
        // Sempre verificar se a tabela migrations existe
        // RefreshDatabase pode limpar o banco entre testes
        try {
            DB::connection('mysql')->select('SELECT 1 FROM migrations LIMIT 1');
        } catch (\Exception $e) {
            // Se não existir, executar migrations fresh para garantir estado limpo
            Artisan::call('migrate:fresh', [
                '--database' => 'mysql',
                '--env' => 'testing',
                '--force' => true,
            ]);
        }
    }
}










