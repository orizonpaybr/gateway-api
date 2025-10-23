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
        Schema::create('pix_keys', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->index();
            $table->enum('key_type', ['cpf', 'cnpj', 'telefone', 'email', 'aleatoria'])->index();
            $table->string('key_value')->index();
            $table->string('key_label')->nullable(); // Nome amigável para a chave
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_default')->default(false); // Chave padrão para saques
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Índices compostos para performance
            $table->index(['user_id', 'is_active']);
            $table->index(['user_id', 'key_type']);
            $table->index(['user_id', 'is_active', 'is_default']); // ✅ Query de chave padrão otimizada
            
            // Garantir que cada chave seja única por usuário
            $table->unique(['user_id', 'key_value']);
            
            // Foreign key
            $table->foreign('user_id')
                ->references('username')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pix_keys');
    }
};

