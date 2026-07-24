<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espelha solicitacoes.adquirente_ref: nominal específica (credenciais) vs
 * executor_ordem = família/provider (ex.: fluxpayments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
            if (! Schema::hasColumn('solicitacoes_cash_out', 'adquirente_ref')) {
                $table->string('adquirente_ref', 64)->nullable()->after('executor_ordem')
                    ->comment('Nominal específica (referencia em adquirentes); executor_ordem = provider/família');
                $table->index('adquirente_ref');
            }
        });
    }

    public function down(): void
    {
        Schema::table('solicitacoes_cash_out', function (Blueprint $table) {
            if (Schema::hasColumn('solicitacoes_cash_out', 'adquirente_ref')) {
                $table->dropIndex(['adquirente_ref']);
                $table->dropColumn('adquirente_ref');
            }
        });
    }
};
