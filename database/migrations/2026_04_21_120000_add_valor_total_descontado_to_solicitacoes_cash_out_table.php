<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Total efetivamente debitado do usuário (saldo + saldo_afiliado) no momento do saque.
 * Usado no estorno em FAILED/CANCELLED para coincidir com BalanceService::decrementCombinedBalance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
            if (! Schema::hasColumn('solicitacoes_cash_out', 'valor_total_descontado')) {
                $table->decimal('valor_total_descontado', 12, 4)->nullable()->after('taxa_cash_out');
            }
        });
    }

    public function down(): void
    {
        Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
            if (Schema::hasColumn('solicitacoes_cash_out', 'valor_total_descontado')) {
                $table->dropColumn('valor_total_descontado');
            }
        });
    }
};
