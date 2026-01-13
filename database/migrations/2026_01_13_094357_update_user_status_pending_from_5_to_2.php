<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Atualiza todos os usuários com status = 5 (pendente antigo) para status = 2 (pendente novo)
     */
    public function up(): void
    {
        // Atualizar todos os usuários com status = 5 para status = 2
        DB::table('users')
            ->where('status', 5)
            ->update(['status' => 2]);
    }

    /**
     * Reverse the migrations.
     * Reverte a migração atualizando status = 2 de volta para status = 5
     */
    public function down(): void
    {
        // Reverter: atualizar status = 2 de volta para status = 5
        DB::table('users')
            ->where('status', 2)
            ->update(['status' => 5]);
    }
};
