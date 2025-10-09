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
        // Inserir a PrimePay7 na tabela de adquirentes
        DB::table('adquirentes')->insert([
            'adquirente' => 'PrimePay7',
            'status' => 0, // Inativo por padrão
            'url' => 'https://api.primepay7.com',
            'referencia' => 'primepay7',
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remover a PrimePay7 da tabela de adquirentes
        DB::table('adquirentes')->where('referencia', 'primepay7')->delete();
    }
};
