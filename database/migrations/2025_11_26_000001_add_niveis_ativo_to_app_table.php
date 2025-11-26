<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adiciona a coluna `niveis_ativo` na tabela `app` caso ainda não exista.
     */
    public function up(): void
    {
        if (Schema::hasTable('app') && !Schema::hasColumn('app', 'niveis_ativo')) {
            Schema::table('app', function (Blueprint $table) {
                // boolean simples, desativado por padrão
                $table->boolean('niveis_ativo')
                    ->default(false)
                    ->after('saque_minimo');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('app') && Schema::hasColumn('app', 'niveis_ativo')) {
            Schema::table('app', function (Blueprint $table) {
                $table->dropColumn('niveis_ativo');
            });
        }
    }
};


