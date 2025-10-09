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
            // Alterar a precisão decimal do campo taxa_fixa_padrao_cash_out de (10,0) para (10,2)
            $table->decimal('taxa_fixa_padrao_cash_out', 10, 2)->default(0.00)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app', function (Blueprint $table) {
            // Reverter para a precisão original (10,0)
            $table->decimal('taxa_fixa_padrao_cash_out', 10, 0)->default(0)->change();
        });
    }
};
