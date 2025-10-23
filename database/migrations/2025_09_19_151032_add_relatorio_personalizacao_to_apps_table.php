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
            $table->boolean('relatorio_entradas_mostrar_meio')->default(true);
            $table->boolean('relatorio_entradas_mostrar_transacao_id')->default(true);
            $table->boolean('relatorio_entradas_mostrar_valor')->default(true);
            $table->boolean('relatorio_entradas_mostrar_valor_liquido')->default(true);
            $table->boolean('relatorio_entradas_mostrar_nome')->default(true);
            $table->boolean('relatorio_entradas_mostrar_documento')->default(true);
            $table->boolean('relatorio_entradas_mostrar_status')->default(true);
            $table->boolean('relatorio_entradas_mostrar_data')->default(true);
            $table->boolean('relatorio_entradas_mostrar_taxa')->default(true);
            
            // Campos para personalização de relatórios de SAÍDAS
            $table->boolean('relatorio_saidas_mostrar_transacao_id')->default(true);
            $table->boolean('relatorio_saidas_mostrar_valor')->default(true);
            $table->boolean('relatorio_saidas_mostrar_nome')->default(true);
            $table->boolean('relatorio_saidas_mostrar_chave_pix')->default(true);
            $table->boolean('relatorio_saidas_mostrar_tipo_chave')->default(true);
            $table->boolean('relatorio_saidas_mostrar_status')->default(true);
            $table->boolean('relatorio_saidas_mostrar_data')->default(true);
            $table->boolean('relatorio_saidas_mostrar_taxa')->default(true);
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