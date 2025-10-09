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
        Schema::create('cashtimes', function (Blueprint $table) {
            $table->id();
            $table->string('secret')->nullable();
            $table->string('url')->default('https://api.cashtime.com.br/v1/');
            $table->string('url_cash_in')->default('https://api.cashtime.com.br/v1/transactions');
            $table->string('url_cash_out')->default('https://api.cashtime.com.br/v1/request/withdraw');
            $table->string('url_webhook_deposit')->nullable();
            $table->string('url_webhook_payment')->nullable();
            $table->decimal('taxa_pix_cash_in', 10, 2)->default(5.00);
            $table->decimal('taxa_pix_cash_out', 10, 2)->default(5.00);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cashtimes');
    }
};
