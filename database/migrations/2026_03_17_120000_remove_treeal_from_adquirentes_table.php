<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove Treeal da listagem de adquirentes (config admin).
     * HeartPay permanece como adquirente ativo.
     */
    public function up(): void
    {
        if (!Schema::hasTable('adquirentes')) {
            return;
        }

        DB::table('adquirentes')->where('referencia', 'treeal')->delete();

        if (Schema::hasTable('users')) {
            if (Schema::hasColumn('users', 'preferred_adquirente')) {
                DB::table('users')
                    ->where('preferred_adquirente', 'treeal')
                    ->update([
                        'preferred_adquirente' => null,
                        'updated_at' => now(),
                    ]);
            }
            if (Schema::hasColumn('users', 'preferred_adquirente_card_billet')) {
                DB::table('users')
                    ->where('preferred_adquirente_card_billet', 'treeal')
                    ->update([
                        'preferred_adquirente_card_billet' => null,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /**
     * Não recria registros Treeal; use a migração 2026_01_20_112400 se precisar reverter dados.
     */
    public function down(): void
    {
        //
    }
};
