<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Tabela para Event Sourcing - auditoria completa de transações financeiras
     */
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 50)->index()->comment('Tipo do evento (PAYMENT_RECEIVED, PAYMENT_SENT, etc)');
            $table->unsignedBigInteger('transaction_id')->index()->comment('ID da transação (solicitacoes ou solicitacoes_cash_out)');
            $table->string('transaction_type', 20)->index()->comment('Tipo: deposit ou withdrawal');
            $table->unsignedBigInteger('user_id')->index()->comment('ID do usuário');
            $table->decimal('amount', 15, 2)->comment('Valor bruto da transação');
            $table->decimal('amount_credited', 15, 2)->nullable()->comment('Valor creditado (para depósitos)');
            $table->decimal('amount_debited', 15, 2)->nullable()->comment('Valor debitado (para saques)');
            $table->decimal('balance_before', 15, 2)->comment('Saldo antes da operação');
            $table->decimal('balance_after', 15, 2)->comment('Saldo após a operação');
            $table->json('metadata')->comment('Metadados (adquirente, webhook_id, etc)');
            $table->timestamps();
            
            // Índices compostos para queries frequentes
            $table->index(['user_id', 'created_at']);
            $table->index(['transaction_id', 'event_type']);
            $table->index(['event_type', 'created_at']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
