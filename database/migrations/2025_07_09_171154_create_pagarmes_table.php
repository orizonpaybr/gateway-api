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
        Schema::create('pagarme', function (Blueprint $table) {
            $table->id();
            $table->string('token')->nullable();
            $table->string('secret')->nullable();
            $table->string('url')->nullable()->default('https://api.pagar.me');
            $table->string('url_cash_in')->nullable()->default('https://api.pagar.me/core/v5/orders');
            $table->string('url_cash_out')->nullable()->default('https://api.pagar.me/core/v5/transaction');
            $table->decimal('taxa_pix_cash_in', 8, 2)->nullable();
            $table->decimal('taxa_pix_cash_out', 8, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pagarme');
    }
};
