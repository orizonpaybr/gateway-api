<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('heartpay')) {
            Schema::create('heartpay', function (Blueprint $table) {
                $table->id();
                $table->string('environment', 50)->default('production');
                $table->string('api_url')->default('https://app.heartpag.com/api/v1/client');
                $table->string('api_key')->nullable()->comment('API Key (hpay_xxx) — Bearer token');
                $table->decimal('taxa_pix_cash_in', 8, 4)->default(0);
                $table->decimal('taxa_pix_cash_out', 8, 4)->default(0);
                $table->string('webhook_secret')->nullable()->comment('Secret para HMAC-SHA256 de webhooks');
                $table->boolean('status')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('heartpay');
    }
};
