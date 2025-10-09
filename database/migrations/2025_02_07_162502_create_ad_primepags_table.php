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
        Schema::create('ad_primepag', function (Blueprint $table) {
            $table->id();
            $table->string('client_id')->nullable();
            $table->string('client_secret')->nullable();
            $table->string('url')->default('https://api.primepag.com.br');
            $table->string('url_cash_in')->default('https://api.primepag.com.br/v1/pix/qrcodes');
            $table->string('url_cash_out')->default('https://api.primepag.com.br/v1/pix/payments');
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
        Schema::dropIfExists('ad_primepag');
    }
};