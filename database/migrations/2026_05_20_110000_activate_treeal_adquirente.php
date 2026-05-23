<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Habilita Treeal (status=1) para testes e uso via override/set-default no admin.
 *
 * Não altera `is_default` — SIMPAY/FYHUB permanecem como global se já estavam.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('adquirentes')
            ->where('referencia', 'treeal')
            ->update([
                'status' => 1,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('adquirentes')
            ->where('referencia', 'treeal')
            ->update([
                'status' => 0,
                'updated_at' => now(),
            ]);
    }
};
