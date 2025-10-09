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
            $table->boolean('taxas_personalizadas_ativas')->default(false)->after('integracao_utmfy');
            
            // Configurações de Depósito
            $table->decimal('taxa_percentual_deposito', 5, 2)->nullable()->after('taxas_personalizadas_ativas');
            $table->decimal('taxa_fixa_deposito', 10, 2)->default(0.00)->after('taxa_percentual_deposito');
            $table->decimal('valor_minimo_deposito', 10, 2)->nullable()->after('taxa_fixa_deposito');
            
            // Configurações de Saque PIX
            $table->decimal('taxa_percentual_pix', 5, 2)->nullable()->after('valor_minimo_deposito');
            $table->decimal('taxa_minima_pix', 10, 2)->nullable()->after('taxa_percentual_pix');
            $table->decimal('taxa_fixa_pix', 10, 2)->default(0.00)->after('taxa_minima_pix');
            $table->decimal('valor_minimo_saque', 10, 2)->nullable()->after('taxa_fixa_pix');
            $table->decimal('limite_mensal_pf', 12, 2)->nullable()->after('valor_minimo_saque');
            $table->decimal('taxa_saque_api', 5, 2)->nullable()->after('limite_mensal_pf');
            $table->decimal('taxa_saque_crypto', 5, 2)->nullable()->after('taxa_saque_api');
            
            // Sistema de Taxas Flexível
            $table->boolean('sistema_flexivel_ativo')->default(false)->after('taxa_saque_crypto');
            $table->decimal('valor_minimo_flexivel', 10, 2)->nullable()->after('sistema_flexivel_ativo');
            $table->decimal('taxa_fixa_baixos', 10, 2)->nullable()->after('valor_minimo_flexivel');
            $table->decimal('taxa_percentual_altos', 5, 2)->nullable()->after('taxa_fixa_baixos');
            
            // Observações
            $table->text('observacoes_taxas')->nullable()->after('taxa_percentual_altos');
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