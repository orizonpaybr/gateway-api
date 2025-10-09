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
        Schema::table('app', function (Blueprint $table) {
            // Campos para taxas de saque específicas
            $table->decimal('taxa_saque_api_padrao', 10, 2)->default(5.00)->after('taxa_cash_out_padrao');
            $table->decimal('taxa_saque_cripto_padrao', 10, 2)->default(1.00)->after('taxa_saque_api_padrao');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app', function (Blueprint $table) {
            $table->dropColumn([
                'taxa_saque_api_padrao',
                'taxa_saque_cripto_padrao'
            ]);
        });
    }
};