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
        Schema::table('ad_mercadopago', function (Blueprint $table) {
            $table->decimal('taxa_pix_cash_in', 10, 2)->default(5.00)->after('access_token');
            $table->decimal('taxa_pix_cash_out', 10, 2)->default(5.00)->after('taxa_pix_cash_in');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ad_mercadopago', function (Blueprint $table) {
            //
        });
    }
};
