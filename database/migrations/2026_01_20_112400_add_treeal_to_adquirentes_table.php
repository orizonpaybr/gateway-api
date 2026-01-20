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
        // Inserir a Treeal na tabela de adquirentes
        DB::table('adquirentes')->insert([
            'adquirente' => 'Treeal',
            'status' => 0, // Inativo por padrão (ativar após configuração)
            'url' => 'https://api.pix-h.amplea.coop.br',
            'referencia' => 'treeal',
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
        // Remover a Treeal da tabela de adquirentes
        DB::table('adquirentes')->where('referencia', 'treeal')->delete();
    }
};
