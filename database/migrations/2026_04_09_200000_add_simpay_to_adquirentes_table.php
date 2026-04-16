<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('adquirentes')->insert([
            'adquirente' => 'SIMPAY',
            'status' => 0,
            'url' => 'https://api.somossimpay.com.br/v2/finance',
            'referencia' => 'simpay',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('adquirentes')->where('referencia', 'simpay')->delete();
    }
};
