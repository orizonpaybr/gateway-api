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
        Schema::create('affiliate_commissions', function (Blueprint $table) {
            $table->id();
            $table->string('user_id'); // ID do filho que gerou a transação
            $table->unsignedBigInteger('affiliate_id'); // ID do pai afiliado que recebe a comissão
            $table->enum('transaction_type', ['cash_in', 'cash_out']); // Tipo de transação
            $table->unsignedBigInteger('solicitacao_id')->nullable(); // ID da transação de depósito
            $table->unsignedBigInteger('solicitacao_cash_out_id')->nullable(); // ID da transação de saque
            $table->decimal('commission_value', 10, 2)->default(0.50); // Valor fixo R$0,50
            $table->decimal('transaction_amount', 10, 2); // Valor da transação original
            $table->enum('status', ['pending', 'paid', 'cancelled'])->default('pending'); // Status da comissão
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('affiliate_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('solicitacao_id')->references('id')->on('solicitacoes')->onDelete('set null');
            $table->foreign('solicitacao_cash_out_id')->references('id')->on('solicitacoes_cash_out')->onDelete('set null');
            
            // Indexes para performance
            $table->index('affiliate_id');
            $table->index('user_id');
            $table->index('status');
            $table->index('transaction_type');
            $table->index(['affiliate_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_commissions');
    }
};
