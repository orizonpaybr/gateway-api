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
        // Inserir a Pixup na tabela de adquirentes
        DB::table('adquirentes')->insert([
            'adquirente' => 'Pixup',
            'status' => 0, // Inativo por padrão
            'url' => 'https://api.pixupbr.com/v2/',
            'referencia' => 'pixup',
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remover a Pixup da tabela de adquirentes
        DB::table('adquirentes')->where('referencia', 'pixup')->delete();
    }
};
