<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Tabela para rastreamento de webhooks processados (idempotência)
     */
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 64)->unique()->comment('Chave única para idempotência');
            $table->string('adquirente', 50)->index()->comment('Nome da adquirente (pixup, bspay, etc)');
            $table->string('transaction_id', 100)->index()->comment('ID da transação');
            $table->enum('status', ['PROCESSING', 'PROCESSED', 'FAILED'])->default('PROCESSING')->index();
            $table->json('payload')->comment('Payload completo do webhook');
            $table->text('error')->nullable()->comment('Mensagem de erro se falhou');
            $table->timestamps();
            
            // Índices compostos para queries frequentes
            $table->index(['adquirente', 'transaction_id']);
            $table->index(['adquirente', 'status', 'created_at']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
