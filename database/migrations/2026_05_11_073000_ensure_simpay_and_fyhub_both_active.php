<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Garante que SIMPAY e FYHUB fiquem com status=1 (ambas habilitadas para uso).
 *
 * Após esta migration o admin não controla mais "ativo/inativo" de cada adquirente
 * individualmente: o que muda é apenas qual delas é a `is_default` (PIX global)
 * via endpoint POST /api/admin/acquirers/{id}/set-default.
 *
 * Não altera `is_default` — SIMPAY continua como global se já estava.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('adquirentes')
            ->whereIn('referencia', ['simpay', 'fyhub'])
            ->update([
                'status' => 1,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('adquirentes')
            ->where('referencia', 'fyhub')
            ->update([
                'status' => 0,
                'updated_at' => now(),
            ]);
    }
};
