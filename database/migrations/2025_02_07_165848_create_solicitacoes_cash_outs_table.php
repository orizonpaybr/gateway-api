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
        Schema::create('solicitacoes_cash_out', function (Blueprint $table) {
            $table->id(); // Para o id auto-incrementável
            $table->string('user_id')->nullable(); // Relacionamento opcional
            $table->string('externalreference');
            $table->decimal('amount', 10, 2);
            $table->string('beneficiaryname');
            $table->string('beneficiarydocument');
            $table->string('pix');
            $table->string('pixkey');
            $table->dateTime('date');
            $table->string('status');
            $table->string('type');
            $table->string('idTransaction')->nullable()->unique();
            $table->decimal('taxa_cash_out', 10, 2);
            $table->decimal('cash_out_liquido', 10, 2);
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
        Schema::dropIfExists('solicitacoes_cash_out');
    }
};