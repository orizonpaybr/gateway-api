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
        // Atualizar níveis de gamificação para corresponder ao frontend
        DB::table('niveis')->where('id', 1)->where('nome', 'Bronze')->update([
            'minimo' => 0.00,
            'maximo' => 100000.00
        ]);

        DB::table('niveis')->where('id', 2)->where('nome', 'Prata')->update([
            'minimo' => 100001.00,
            'maximo' => 500000.00
        ]);

        DB::table('niveis')->where('id', 3)->where('nome', 'Ouro')->update([
            'minimo' => 500001.00,
            'maximo' => 1000000.00
        ]);

        DB::table('niveis')->where('id', 4)->where('nome', 'Safira')->update([
            'minimo' => 1000001.00,
            'maximo' => 5000000.00
        ]);

        DB::table('niveis')->where('id', 5)->where('nome', 'Diamante')->update([
            'minimo' => 5000001.00,
            'maximo' => 10000000.00
        ]);

        // Garantir que o sistema de níveis está ativo
        DB::table('app')->where('id', 1)->update(['niveis_ativo' => 1]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverter para os valores originais (baseado no martinspay-app.sql)
        DB::table('niveis')->where('id', 1)->where('nome', 'Bronze')->update([
            'minimo' => 0.00,
            'maximo' => 100000.00
        ]);

        DB::table('niveis')->where('id', 2)->where('nome', 'Prata')->update([
            'minimo' => 100001.00,
            'maximo' => 500000.00
        ]);

        DB::table('niveis')->where('id', 3)->where('nome', 'Ouro')->update([
            'minimo' => 500001.00,
            'maximo' => 1000000.00
        ]);

        DB::table('niveis')->where('id', 4)->where('nome', 'Safira')->update([
            'minimo' => 1000001.00,
            'maximo' => 5000000.00
        ]);

        DB::table('niveis')->where('id', 5)->where('nome', 'Diamante')->update([
            'minimo' => 5000001.00,
            'maximo' => 10000000.00
        ]);
    }
};
