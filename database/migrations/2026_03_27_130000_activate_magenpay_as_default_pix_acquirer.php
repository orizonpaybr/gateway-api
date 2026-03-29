<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O PixAcquirerManager só regista "magenpay". O padrão global PIX deve ser magenpay
     * (ex.: treeal não tem serviço registado → NullPixAcquirerService → 503).
     */
    public function up(): void
    {
        if (! Schema::hasTable('adquirentes')) {
            return;
        }

        DB::table('adquirentes')->update(['is_default' => 0]);

        $exists = DB::table('adquirentes')->where('referencia', 'magenpay')->exists();
        if (! $exists) {
            DB::table('adquirentes')->insert([
                'adquirente' => 'Magen',
                'status' => 1,
                'url' => 'https://sandbox.api.magenpay.io',
                'referencia' => 'magenpay',
                'is_default' => 1,
                'is_default_card_billet' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('adquirentes')->where('referencia', 'magenpay')->update([
            'status' => 1,
            'is_default' => 1,
            'updated_at' => now(),
        ]);
    }

    /**
     * Reativa Treeal como padrão PIX se existir (comportamento anterior).
     */
    public function down(): void
    {
        if (! Schema::hasTable('adquirentes')) {
            return;
        }

        DB::table('adquirentes')->where('referencia', 'magenpay')->update([
            'is_default' => 0,
            'updated_at' => now(),
        ]);

        DB::table('adquirentes')->update(['is_default' => 0]);

        $treeal = DB::table('adquirentes')->where('referencia', 'treeal')->first();
        if ($treeal !== null) {
            DB::table('adquirentes')->where('referencia', 'treeal')->update([
                'status' => 1,
                'is_default' => 1,
                'updated_at' => now(),
            ]);
        }
    }
};
