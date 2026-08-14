<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reintegração FYHUB: código das integrações (API QRCode cash-in + API Contas
 * cash-out, ambas OAuth2 + mTLS) voltou ao projeto. Recria a linha em
 * `adquirentes` que a migration de depreciação (remove_simpay_and_fyhub) apagou.
 *
 * status = 0 (inativa): a adquirente só é roteável depois que o admin a ativa
 * pelo painel, o que exige antes configurar no .env as credenciais OAuth e os
 * certificados mTLS (FYHUB_* / FYHUB_CONTAS_*). Não mexe em is_default: a
 * principal (treeal) continua sendo a global.
 *
 * SIMPAY segue removida — não é recriada aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('adquirentes')->updateOrInsert(
            ['referencia' => 'fyhub'],
            [
                'adquirente' => 'FYHUB',
                'status' => 0,
                'is_default' => 0,
                'url' => 'https://api.qrcode.fyhub.com.br',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('adquirentes')->where('referencia', 'fyhub')->delete();
    }
};
