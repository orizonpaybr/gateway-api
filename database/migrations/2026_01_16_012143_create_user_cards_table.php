<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Tabela para armazenar cartões tokenizados dos usuários
     * Segue padrão PCI DSS - apenas dados não sensíveis são armazenados
     */
    public function up(): void
    {
        Schema::create('user_cards', function (Blueprint $table) {
            $table->id();
            
            // Relacionamento com usuário
            $table->unsignedBigInteger('user_id');
            
            // IDs da Pagar.me
            $table->string('card_id')->comment('ID do cartão na Pagar.me');
            $table->string('customer_id')->nullable()->comment('ID do cliente na Pagar.me');
            
            // Dados do cartão (não sensíveis - PCI DSS compliant)
            $table->string('brand')->nullable()->comment('Bandeira: Visa, Mastercard, etc');
            $table->string('first_six_digits', 6)->nullable()->comment('BIN do cartão');
            $table->string('last_four_digits', 4)->nullable()->comment('Últimos 4 dígitos');
            $table->string('holder_name')->nullable()->comment('Nome do titular');
            $table->unsignedTinyInteger('exp_month')->nullable()->comment('Mês de expiração');
            $table->unsignedSmallInteger('exp_year')->nullable()->comment('Ano de expiração');
            
            // Status do cartão
            $table->string('status')->default('active')->comment('active, expired, deleted');
            
            // Endereço de cobrança (JSON)
            $table->json('billing_address')->nullable();
            
            // Metadados
            $table->string('label')->nullable()->comment('Apelido do cartão definido pelo usuário');
            $table->boolean('is_default')->default(false)->comment('Cartão padrão do usuário');
            
            // Controle
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Índices
            $table->index('user_id');
            $table->index('card_id');
            $table->index('customer_id');
            $table->unique(['user_id', 'card_id']);
            
            // Foreign key
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_cards');
    }
};
