<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adiciona campos específicos para pagamentos com cartão de crédito
     */
    public function up(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            // Número de parcelas do pagamento
            $table->integer('installments')->nullable()->after('method')->comment('Número de parcelas do pagamento');
            
            // ID da cobrança na Pagar.me (charge_id)
            $table->string('charge_id')->nullable()->after('idTransaction')->comment('ID da cobrança no gateway');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('solicitacoes', function (Blueprint $table) {
            $table->dropColumn(['installments', 'charge_id']);
        });
    }
};
