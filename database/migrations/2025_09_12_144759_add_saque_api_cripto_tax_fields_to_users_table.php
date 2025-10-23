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
        Schema::table('users', function (Blueprint $table) {
            // Campos para taxas de saque específicas do usuário
            $table->decimal('taxa_saque_api', 10, 2)->nullable();
            $table->decimal('taxa_saque_cripto', 10, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'taxa_saque_api',
                'taxa_saque_cripto'
            ]);
        });
    }
};