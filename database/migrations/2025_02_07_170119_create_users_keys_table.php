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
        Schema::create('users_key', function (Blueprint $table) {
            $table->id(); // Para o id auto-incrementável
            $table->string('user_id')->nullable(); // Relacionamento opcional
            $table->string('api_key'); // Campo para armazenar a chave API
            $table->string('status', 50); // Campo status com 50 caracteres
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
        Schema::dropIfExists('users_key');
    }
};