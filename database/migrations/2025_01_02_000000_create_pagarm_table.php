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
        Schema::create('pagarm', function (Blueprint $table) {
            $table->id();
            $table->string('client_id')->nullable();
            $table->string('client_secret')->nullable();
            $table->string('url')->default('https://api.pagarm.com.br/v1');
            $table->string('webhook_secret')->nullable();
            $table->decimal('taxa_adquirente_entradas', 5, 2)->default(0.50); // Taxa de 0,50%
            $table->decimal('taxa_adquirente_saidas', 5, 2)->default(0.50); // Taxa de 0,50%
            $table->boolean('status')->default(false);
            $table->string('api_key')->nullable();
            $table->enum('environment', ['sandbox', 'production'])->default('sandbox');
            $table->string('merchant_id')->nullable();
            $table->string('account_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pagarm');
    }
};


