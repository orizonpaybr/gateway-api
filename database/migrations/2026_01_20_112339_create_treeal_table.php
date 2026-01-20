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
        Schema::create('treeal', function (Blueprint $table) {
            $table->id();
            
            // Ambiente
            $table->enum('environment', ['sandbox', 'production'])->default('sandbox');
            
            // URLs das APIs
            $table->string('qrcodes_api_url')->default('https://api.pix-h.amplea.coop.br');
            $table->string('accounts_api_url')->default('https://secureapi.bancodigital.hmg.onz.software/api/v2');
            
            // Certificado Digital
            $table->string('certificate_path')->nullable()->comment('Caminho do certificado .PFX');
            $table->string('certificate_password')->nullable()->comment('Senha do certificado');
            
            // Credenciais OAuth2 (para Accounts API)
            $table->string('client_id')->nullable()->comment('Client ID para OAuth2');
            $table->string('client_secret')->nullable()->comment('Client Secret para OAuth2');
            
            // Chave PIX secundária (para testes)
            $table->string('pix_key_secondary')->nullable()->comment('Chave PIX secundária para testes');
            
            // Taxas
            $table->decimal('taxa_pix_cash_in', 10, 2)->default(0.00)->comment('Taxa % para depósitos');
            $table->decimal('taxa_pix_cash_out', 10, 2)->default(0.00)->comment('Taxa % para saques');
            
            // Webhook
            $table->string('webhook_secret')->nullable()->comment('Secret para validar webhooks');
            
            // Status
            $table->boolean('status')->default(false)->comment('Adquirente ativa/inativa');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('treeal');
    }
};
