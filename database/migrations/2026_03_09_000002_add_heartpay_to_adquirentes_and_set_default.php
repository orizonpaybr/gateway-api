<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('adquirentes')) {
            return;
        }

        $exists = DB::table('adquirentes')->where('referencia', 'heartpay')->exists();

        if (!$exists) {
            DB::table('adquirentes')->insert([
                'adquirente' => 'HeartPay',
                'referencia' => 'heartpay',
                'status'     => 1,
                'url'        => 'https://app.heartpag.com/api/v1/client',
                'is_default' => 1,
                'is_default_card_billet' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $othersQuery = DB::table('adquirentes')->where('referencia', '!=', 'heartpay');
        if (Schema::hasColumn('adquirentes', 'payment_type')) {
            $othersQuery->where('payment_type', 'pix');
        }
        $othersQuery->update([
            'is_default' => 0,
            'status'     => 0,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('adquirentes')) {
            return;
        }

        DB::table('adquirentes')->where('referencia', 'heartpay')->delete();

        $treealQuery = DB::table('adquirentes')->where('referencia', 'treeal');
        if (Schema::hasColumn('adquirentes', 'payment_type')) {
            $treealQuery->where('payment_type', 'pix');
        }
        $treealQuery->update([
            'is_default' => 1,
            'status'     => 1,
            'updated_at' => now(),
        ]);
    }
};
