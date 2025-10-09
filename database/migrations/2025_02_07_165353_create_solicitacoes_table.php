<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('solicitacoes', function (Blueprint $table) {
            $table->id(); // Para o id auto-incrementável
            $table->string('user_id')->nullable(); // Relacionamento opcional
            $table->string('externalreference');
            $table->decimal('amount', 10, 2);
            $table->string('client_name');
            $table->string('client_document');
            $table->string('client_email');
            $table->dateTime('date');
            $table->string('status');
            $table->string('idTransaction')->unique();
            $table->decimal('deposito_liquido', 10, 2);
            $table->string('qrcode_pix', 500);
            $table->string('paymentcode', 500);
            $table->text('paymentCodeBase64');
            $table->string('adquirente_ref');
            $table->decimal('taxa_cash_in', 10, 2);
            $table->decimal('taxa_pix_cash_in_adquirente', 10, 2);
            $table->decimal('taxa_pix_cash_in_valor_fixo', 10, 2);
            $table->string('client_telefone', 15);
            $table->string('executor_ordem');
            $table->string('descricao_transacao');
            $table->string('callback')->nullable();
            $table->string('split_email')->nullable();
            $table->string('split_percentage')->nullable();
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('set null'); // Relacionamento com a tabela users
            $table->timestamps(); // Para created_at e updated_at
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('solicitacoes');
    }
};
