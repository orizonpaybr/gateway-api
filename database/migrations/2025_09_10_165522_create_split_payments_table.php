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
        Schema::create('split_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('solicitacao_id');
            $table->string('user_id');
            $table->string('split_email');
            $table->decimal('split_percentage', 5, 2)->default(0);
            $table->decimal('split_amount', 10, 2)->default(0);
            $table->enum('split_status', ['pending', 'processing', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->enum('split_type', ['percentage', 'fixed', 'partner', 'affiliate'])->default('percentage');
            $table->text('description')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            // Índices
            $table->index(['solicitacao_id']);
            $table->index(['user_id']);
            $table->index(['split_status']);
            $table->index(['split_type']);
            $table->index(['created_at']);

            // Foreign keys
            $table->foreign('solicitacao_id')->references('id')->on('solicitacoes')->onDelete('cascade');
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('split_payments');
    }
};
