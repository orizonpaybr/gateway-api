<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Adicionar PagArm na tabela adquirentes
        DB::table('adquirentes')->insert([
            'adquirente' => 'pagarm',
            'status' => 0,
            'url' => 'https://api.pagarm.com.br/v1',
            'referencia' => 'pagarm',
            'is_default' => 0,
            'is_default_card_billet' => 0,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('adquirentes')->where('referencia', 'pagarm')->delete();
    }
};


