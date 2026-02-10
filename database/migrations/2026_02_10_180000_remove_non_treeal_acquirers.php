<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove todos os adquirentes exceto Treeal.
     * Apenas Treeal está em uso no momento.
     */
    public function up(): void
    {
        if (!Schema::hasTable('adquirentes')) {
            return;
        }

        DB::table('adquirentes')
            ->where('referencia', '!=', 'treeal')
            ->delete();
    }

    /**
     * Reverter não restaura os adquirentes removidos.
     */
    public function down(): void
    {
        // Opcional: não restauramos Pixup, PrimePay7, XDPag etc.
    }
};
