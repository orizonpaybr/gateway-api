<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identificador do PAGAMENTO na adquirente (não do charge). Algumas adquirentes
 * (ex.: Paytler) amarram VÁRIOS QRs/charges ao MESMO pagamento (txid) — pagar 1
 * marca todos como pagos. Sem deduplicar por este id, o mesmo pagamento credita
 * o saldo N vezes (cliente paga 5, recebe 20). Este campo é a chave de dedup:
 * um pagamento credita no máximo um depósito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            if (! Schema::hasColumn('solicitacoes', 'provider_payment_id')) {
                $table->string('provider_payment_id', 100)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            if (Schema::hasColumn('solicitacoes', 'provider_payment_id')) {
                $table->dropColumn('provider_payment_id');
            }
        });
    }
};
