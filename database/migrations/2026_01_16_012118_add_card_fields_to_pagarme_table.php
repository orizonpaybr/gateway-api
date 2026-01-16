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
        Schema::table('pagarme', function (Blueprint $table) {
            // Chave pública para tokenização (Tokenizecard JS)
            $table->string('public_key')->nullable()->after('secret');
            
            // Secret para validação de webhooks
            $table->string('webhook_secret')->nullable()->after('public_key');
            
            // Ambiente: sandbox ou production
            $table->string('environment')->default('sandbox')->after('webhook_secret');
            
            // Taxas de cartão de crédito
            $table->decimal('card_tx_percent', 8, 2)->nullable()->default(0)->after('taxa_pix_cash_out');
            $table->decimal('card_tx_fixed', 8, 2)->nullable()->default(0)->after('card_tx_percent');
            
            // Dias para disponibilização do valor de cartão
            $table->integer('card_days_availability')->nullable()->default(30)->after('card_tx_fixed');
            
            // Flag para habilitar/desabilitar pagamentos com cartão
            $table->boolean('card_enabled')->default(false)->after('card_days_availability');
            
            // Flag para habilitar 3D Secure
            $table->boolean('use_3ds')->default(true)->after('card_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pagarme', function (Blueprint $table) {
            $table->dropColumn([
                'public_key',
                'webhook_secret',
                'environment',
                'card_tx_percent',
                'card_tx_fixed',
                'card_days_availability',
                'card_enabled',
                'use_3ds',
            ]);
        });
    }
};
