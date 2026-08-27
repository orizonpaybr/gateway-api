<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Integração PAYTLER: adiciona a linha em `adquirentes`.
 *
 * status = 0 (inativa): só roteável depois que o admin ativa pelo painel, o que
 * exige antes configurar no .env as credenciais (PAYTLER_CLIENT_ID/SECRET).
 * Não mexe em is_default.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('adquirentes')->updateOrInsert(
            ['referencia' => 'paytler'],
            [
                'adquirente' => 'PAYTLER',
                'status' => 0,
                'is_default' => 0,
                'url' => 'https://api.paytler.com',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('adquirentes')->where('referencia', 'paytler')->delete();
    }
};
