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
        Schema::create('app', function (Blueprint $table) {
            $table->id();
            $table->integer('numero_users')->default(0);
            $table->decimal('faturamento_total', 10, 2)->default(0.00);
            $table->decimal('total_transacoes', 10, 2)->default(0.00);
            $table->integer('visitantes')->default(0);
            $table->boolean('manutencao')->default(false);
            $table->decimal('taxa_cash_in_padrao', 10, 2)->default(4.00);
            $table->decimal('taxa_cash_out_padrao', 10, 2)->default(4.00);
            $table->decimal('taxa_fixa_padrao', 10, 2)->default(5.00);
            $table->string('sms_url_cadastro_pendente')->nullable();
            $table->string('sms_url_cadastro_ativo')->nullable();
            $table->string('sms_url_notificacao_user')->nullable();
            $table->string('sms_url_redefinir_senha')->nullable();
            $table->string('sms_url_autenticar_admin')->nullable();
            $table->decimal('taxa_pix_valor_real_cash_in_padrao', 10, 2)->default(5.00);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app');
    }
};