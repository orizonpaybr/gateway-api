<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Remover campos de taxas personalizadas
            $table->dropColumn([
                'taxas_personalizadas_ativas',
                'taxa_cash_in',
                'taxa_cash_in_fixa',
                'taxa_cash_out',
                'taxa_cash_out_fixa',
                'taxa_saque_api',
                'taxa_saque_cripto',
                'taxa_flexivel_ativa',
                'taxa_flexivel_valor_minimo',
                'taxa_flexivel_fixa_baixo',
                'taxa_flexivel_percentual_alto'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Recriar campos se necessário (para rollback)
            $table->boolean('taxas_personalizadas_ativas')->default(false);
            $table->decimal('taxa_cash_in', 5, 2)->nullable();
            $table->decimal('taxa_cash_in_fixa', 10, 2)->default(0.00);
            $table->decimal('taxa_cash_out', 5, 2)->nullable();
            $table->decimal('taxa_cash_out_fixa', 10, 2)->default(0.00);
            $table->decimal('taxa_saque_api', 5, 2)->nullable();
            $table->decimal('taxa_saque_cripto', 5, 2)->nullable();
            $table->boolean('taxa_flexivel_ativa')->default(false);
            $table->decimal('taxa_flexivel_valor_minimo', 10, 2)->nullable();
            $table->decimal('taxa_flexivel_fixa_baixo', 10, 2)->nullable();
            $table->decimal('taxa_flexivel_percentual_alto', 5, 2)->nullable();
        });
    }
};