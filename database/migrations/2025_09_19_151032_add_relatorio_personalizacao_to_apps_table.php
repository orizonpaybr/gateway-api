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
        Schema::table('app', function (Blueprint $table) {
            // Campos para personalização de relatórios de ENTRADAS
            $table->boolean('relatorio_entradas_mostrar_meio')->default(true)->after('taxa_flexivel_percentual_alto');
            $table->boolean('relatorio_entradas_mostrar_transacao_id')->default(true)->after('relatorio_entradas_mostrar_meio');
            $table->boolean('relatorio_entradas_mostrar_valor')->default(true)->after('relatorio_entradas_mostrar_transacao_id');
            $table->boolean('relatorio_entradas_mostrar_valor_liquido')->default(true)->after('relatorio_entradas_mostrar_valor');
            $table->boolean('relatorio_entradas_mostrar_nome')->default(true)->after('relatorio_entradas_mostrar_valor_liquido');
            $table->boolean('relatorio_entradas_mostrar_documento')->default(true)->after('relatorio_entradas_mostrar_nome');
            $table->boolean('relatorio_entradas_mostrar_status')->default(true)->after('relatorio_entradas_mostrar_documento');
            $table->boolean('relatorio_entradas_mostrar_data')->default(true)->after('relatorio_entradas_mostrar_status');
            $table->boolean('relatorio_entradas_mostrar_taxa')->default(true)->after('relatorio_entradas_mostrar_data');
            
            // Campos para personalização de relatórios de SAÍDAS
            $table->boolean('relatorio_saidas_mostrar_transacao_id')->default(true)->after('relatorio_entradas_mostrar_taxa');
            $table->boolean('relatorio_saidas_mostrar_valor')->default(true)->after('relatorio_saidas_mostrar_transacao_id');
            $table->boolean('relatorio_saidas_mostrar_nome')->default(true)->after('relatorio_saidas_mostrar_valor');
            $table->boolean('relatorio_saidas_mostrar_chave_pix')->default(true)->after('relatorio_saidas_mostrar_nome');
            $table->boolean('relatorio_saidas_mostrar_tipo_chave')->default(true)->after('relatorio_saidas_mostrar_chave_pix');
            $table->boolean('relatorio_saidas_mostrar_status')->default(true)->after('relatorio_saidas_mostrar_tipo_chave');
            $table->boolean('relatorio_saidas_mostrar_data')->default(true)->after('relatorio_saidas_mostrar_status');
            $table->boolean('relatorio_saidas_mostrar_taxa')->default(true)->after('relatorio_saidas_mostrar_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app', function (Blueprint $table) {
            // Remover campos de personalização de relatórios de ENTRADAS
            $table->dropColumn([
                'relatorio_entradas_mostrar_meio',
                'relatorio_entradas_mostrar_transacao_id',
                'relatorio_entradas_mostrar_valor',
                'relatorio_entradas_mostrar_valor_liquido',
                'relatorio_entradas_mostrar_nome',
                'relatorio_entradas_mostrar_documento',
                'relatorio_entradas_mostrar_status',
                'relatorio_entradas_mostrar_data',
                'relatorio_entradas_mostrar_taxa'
            ]);
            
            // Remover campos de personalização de relatórios de SAÍDAS
            $table->dropColumn([
                'relatorio_saidas_mostrar_transacao_id',
                'relatorio_saidas_mostrar_valor',
                'relatorio_saidas_mostrar_nome',
                'relatorio_saidas_mostrar_chave_pix',
                'relatorio_saidas_mostrar_tipo_chave',
                'relatorio_saidas_mostrar_status',
                'relatorio_saidas_mostrar_data',
                'relatorio_saidas_mostrar_taxa'
            ]);
        });
    }
};