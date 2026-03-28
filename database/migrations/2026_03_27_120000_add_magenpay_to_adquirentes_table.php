<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $exists = DB::table('adquirentes')->where('referencia', 'magenpay')->exists();
        if ($exists) {
            return;
        }

        DB::table('adquirentes')->insert([
            'adquirente' => 'Magen',
            'status' => 0,
            'url' => 'https://sandbox.api.magenpay.io',
            'referencia' => 'magenpay',
            'is_default' => 0,
            'is_default_card_billet' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('adquirentes')->where('referencia', 'magenpay')->delete();
    }
};
