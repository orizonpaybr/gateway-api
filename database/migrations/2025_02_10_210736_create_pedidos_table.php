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
        Schema::create('checkout_vendas', function (Blueprint $table) {
            $table->id(); // Para o id auto-incrementável
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('document')->nullable();
            $table->string('phone')->nullable();
            $table->string('cep')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->unsignedBigInteger('checkout_id')->nullable();
            $table->foreign('checkout_id')->references('id')->on('checkout_build')->onDelete('cascade');
            $table->timestamps(); // Para created_at e updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checkout_vendas');
    }
};
