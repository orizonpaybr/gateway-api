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
        // Remover campos de taxa da tabela BSPay
        Schema::table('bspay', function (Blueprint $table) {
            $table->dropColumn(['taxa_pix_cash_in', 'taxa_pix_cash_out']);
        });

        // Remover campos de taxa da tabela Pixup
        Schema::table('pixup', function (Blueprint $table) {
            $table->dropColumn(['taxa_pix_cash_in', 'taxa_pix_cash_out']);
        });

        // Remover campos de taxa da tabela Woovi
        Schema::table('woovi', function (Blueprint $table) {
            $table->dropColumn(['taxa_pix_cash_in', 'taxa_pix_cash_out']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restaurar campos de taxa da tabela BSPay
        Schema::table('bspay', function (Blueprint $table) {
            $table->decimal('taxa_pix_cash_in', 5, 2)->default(0);
            $table->decimal('taxa_pix_cash_out', 5, 2)->default(0);
        });

        // Restaurar campos de taxa da tabela Pixup
        Schema::table('pixup', function (Blueprint $table) {
            $table->decimal('taxa_pix_cash_in', 10, 2)->default(0.00);
            $table->decimal('taxa_pix_cash_out', 10, 2)->default(0.00);
        });

        // Restaurar campos de taxa da tabela Woovi
        Schema::table('woovi', function (Blueprint $table) {
            $table->decimal('taxa_pix_cash_in', 5, 2)->default(0.00);
            $table->decimal('taxa_pix_cash_out', 5, 2)->default(0.00);
        });
    }
};
