<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Toggle por usuário entre cobrança por taxa FIXA (centavos/reais) e por
     * PORCENTAGEM sobre o valor da transação. São mutuamente exclusivos: quando
     * o modo percentual está ativo, a taxa fixa do usuário é ignorada.
     *
     * As colunas de percentual (taxa_percentual_deposito / taxa_percentual_pix)
     * já existem na tabela users.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'taxa_modo_percentual')) {
                $table->boolean('taxa_modo_percentual')->default(false)->after('taxas_personalizadas_ativas');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'taxa_modo_percentual')) {
                $table->dropColumn('taxa_modo_percentual');
            }
        });
    }
};
