<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Define Treeal como adquirente PIX padrão e ativa.
     * Necessário para que depósitos via PIX (wallet/deposit/payment) funcionem.
     */
    public function up(): void
    {
        if (!Schema::hasTable('adquirentes')) {
            return;
        }

        // Remover is_default de outros adquirentes PIX para ter apenas um padrão
        DB::table('adquirentes')->update(['is_default' => 0]);

        // Ativar Treeal e definir como padrão para PIX
        DB::table('adquirentes')
            ->where('referencia', 'treeal')
            ->update([
                'status' => 1,
                'is_default' => 1,
                'updated_at' => now(),
            ]);

        // Criar/atualizar registro na tabela treeal se existir
        if (Schema::hasTable('treeal')) {
            $exists = DB::table('treeal')->exists();
            
            if (!$exists) {
                // Criar registro inicial
                DB::table('treeal')->insert([
                    'environment' => 'sandbox',
                    'qrcodes_api_url' => 'https://api.pix-h.amplea.coop.br',
                    'accounts_api_url' => 'https://secureapi.bancodigital.hmg.onz.software/api/v2',
                    'status' => 1, // Ativo
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                // Atualizar para ativo
                DB::table('treeal')->update([
                    'status' => 1,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('adquirentes')) {
            return;
        }

        DB::table('adquirentes')
            ->where('referencia', 'treeal')
            ->update([
                'status' => 0,
                'is_default' => 0,
                'updated_at' => now(),
            ]);
    }
};
