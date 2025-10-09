<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkout_build', function (Blueprint $table) {
            $table->id();
            $table->string('name_produto');
            $table->string('valor');
            $table->string('referencia')->nullable();
            $table->string('logo_produto')->nullable();
            $table->string('obrigado_page');
            $table->string('key_gateway');
            $table->boolean('ativo')->default(true);
            $table->string('email');
            $table->string('url_checkout')->nullable();
            $table->string('banner_produto')->nullable();
            $table->string('user_id')->nullable();

            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_build');
    }
};