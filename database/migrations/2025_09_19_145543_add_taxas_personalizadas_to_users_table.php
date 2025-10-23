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
            // Campo master para ativar/desativar taxas personalizadas
            $table->boolean('taxas_personalizadas_ativas')->default(false);
            
            // Configurações de Depósito
            $table->decimal('taxa_percentual_deposito', 5, 2)->nullable();
            $table->decimal('taxa_fixa_deposito', 10, 2)->default(0.00);
            $table->decimal('valor_minimo_deposito', 10, 2)->nullable();
            
            // Configurações de Saque PIX
            $table->decimal('taxa_percentual_pix', 5, 2)->nullable();
            $table->decimal('taxa_minima_pix', 10, 2)->nullable();
            $table->decimal('taxa_fixa_pix', 10, 2)->default(0.00);
            $table->decimal('valor_minimo_saque', 10, 2)->nullable();
            $table->decimal('limite_mensal_pf', 12, 2)->nullable();
            $table->decimal('taxa_saque_api', 5, 2)->nullable();
            $table->decimal('taxa_saque_crypto', 5, 2)->nullable();
            
            // Sistema de Taxas Flexível
            $table->boolean('sistema_flexivel_ativo')->default(false);
            $table->decimal('valor_minimo_flexivel', 10, 2)->nullable();
            $table->decimal('taxa_fixa_baixos', 10, 2)->nullable();
            $table->decimal('taxa_percentual_altos', 5, 2)->nullable();
            
            // Observações
            $table->text('observacoes_taxas')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'taxas_personalizadas_ativas',
                'taxa_percentual_deposito',
                'taxa_fixa_deposito',
                'valor_minimo_deposito',
                'taxa_percentual_pix',
                'taxa_minima_pix',
                'taxa_fixa_pix',
                'valor_minimo_saque',
                'limite_mensal_pf',
                'taxa_saque_api',
                'taxa_saque_crypto',
                'sistema_flexivel_ativo',
                'valor_minimo_flexivel',
                'taxa_fixa_baixos',
                'taxa_percentual_altos',
                'observacoes_taxas'
            ]);
        });
    }
};