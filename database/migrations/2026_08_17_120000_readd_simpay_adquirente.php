<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reintegração SIMPAY: código da integração (OAuth + HMAC-SHA512, cash-in/out,
 * status, chargeback, MED, decode QR) voltou ao projeto. Recria a linha em
 * `adquirentes` que a migration de depreciação apagou.
 *
 * status = 0 (inativa): só roteável depois que o admin ativa pelo painel, o que
 * exige antes configurar no .env as credenciais (SIMPAY_CLIENT_ID/SECRET/HMAC_KEY)
 * e a conta de origem (SIMPAY_SOURCE_ACCOUNT_*). Não mexe em is_default.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('adquirentes')->updateOrInsert(
            ['referencia' => 'simpay'],
            [
                'adquirente' => 'SIMPAY',
                'status' => 0,
                'is_default' => 0,
                'url' => 'https://api.somossimpay.com.br',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('adquirentes')->where('referencia', 'simpay')->delete();
    }
};
