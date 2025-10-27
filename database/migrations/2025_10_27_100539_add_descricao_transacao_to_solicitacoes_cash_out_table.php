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
        // Adicionar coluna em solicitacoes_cash_out se não existir
        if (!Schema::hasColumn('solicitacoes_cash_out', 'descricao_transacao')) {
            Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
                $table->string('descricao_transacao')->nullable()->after('cash_out_liquido');
            });
        }

        // Adicionar coluna em solicitacoes se não existir
        if (!Schema::hasColumn('solicitacoes', 'descricao_transacao')) {
            Schema::table('solicitacoes', function (Blueprint $table) {
                $table->string('descricao_transacao')->nullable()->after('executor_ordem');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remover coluna de solicitacoes_cash_out
        if (Schema::hasColumn('solicitacoes_cash_out', 'descricao_transacao')) {
            Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
                $table->dropColumn('descricao_transacao');
            });
        }

        // Remover coluna de solicitacoes
        if (Schema::hasColumn('solicitacoes', 'descricao_transacao')) {
            Schema::table('solicitacoes', function (Blueprint $table) {
                $table->dropColumn('descricao_transacao');
            });
        }
    }
};
