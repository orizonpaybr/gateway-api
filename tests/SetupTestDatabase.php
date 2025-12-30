<?php

/**
 * Script para configurar banco de dados de teste
 * 
 * Execute antes de rodar testes de integração:
 * php artisan test:setup-db
 */

namespace Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class SetupTestDatabase
{
    public static function setup(): void
    {
        echo "🔧 Configurando banco de dados de teste...\n";
        
        // Rodar migrations
        echo "📦 Rodando migrations...\n";
        Artisan::call('migrate', [
            '--database' => 'mysql',
            '--env' => 'testing',
        ]);
        
        echo "✅ Banco de dados de teste configurado!\n";
    }
}
















