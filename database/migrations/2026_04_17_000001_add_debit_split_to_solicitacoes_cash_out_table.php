<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partes do débito combinado (afiliado vs principal) para estorno fiel ao BalanceService::decrementCombinedBalance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
            // Usar taxa_cash_out (existe desde a criação da tabela). Não usar valor_total_descontado:
            // essa coluna é adicionada em migration 2026_04_21_* que roda depois desta.
            if (! Schema::hasColumn('solicitacoes_cash_out', 'debito_saldo_afiliado')) {
                $table->decimal('debito_saldo_afiliado', 12, 4)->nullable()->after('taxa_cash_out');
            }
            if (! Schema::hasColumn('solicitacoes_cash_out', 'debito_saldo_principal')) {
                $table->decimal('debito_saldo_principal', 12, 4)->nullable()->after('debito_saldo_afiliado');
            }
        });
    }

    public function down(): void
    {
        Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
            if (Schema::hasColumn('solicitacoes_cash_out', 'debito_saldo_principal')) {
                $table->dropColumn('debito_saldo_principal');
            }
            if (Schema::hasColumn('solicitacoes_cash_out', 'debito_saldo_afiliado')) {
                $table->dropColumn('debito_saldo_afiliado');
            }
        });
    }
};
