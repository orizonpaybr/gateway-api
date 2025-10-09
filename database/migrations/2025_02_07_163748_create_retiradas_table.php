<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('retiradas', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->nullable();
            $table->string('referencia')->nullable();
            $table->decimal('valor', 10, 2);
            $table->decimal('valor_liquido', 10, 2);
            $table->string('tipo_chave');
            $table->string('chave');
            $table->string('status');
            $table->timestamp('data_solicitacao')->useCurrent();
            $table->timestamp('data_pagamento')->nullable()->useCurrentOnUpdate();
            $table->decimal('taxa_cash_out', 10, 2);

            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retiradas');
    }
};