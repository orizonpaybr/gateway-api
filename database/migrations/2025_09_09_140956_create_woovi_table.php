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
        Schema::create('woovi', function (Blueprint $table) {
            $table->id();
            $table->string('app_id')->unique();
            $table->string('api_key');
            $table->string('webhook_secret')->nullable();
            $table->string('url')->default('https://api.woovi.com');
            $table->boolean('sandbox')->default(false);
            $table->decimal('taxa_pix_cash_in', 5, 2)->default(0.00);
            $table->decimal('taxa_pix_cash_out', 5, 2)->default(0.00);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('woovi');
    }
};
