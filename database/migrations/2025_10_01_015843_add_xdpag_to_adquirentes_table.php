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
        // Inserir a XDPag na tabela de adquirentes
        DB::table('adquirentes')->insert([
            'adquirente' => 'XDPag',
            'status' => 0, // Inativo por padrão
            'url' => 'https://api.xdpag.com',
            'referencia' => 'xdpag',
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remover a XDPag da tabela de adquirentes
        DB::table('adquirentes')->where('referencia', 'xdpag')->delete();
    }
};