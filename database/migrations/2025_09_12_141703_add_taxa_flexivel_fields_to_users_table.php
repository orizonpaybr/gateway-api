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
            $table->boolean('taxa_flexivel_ativa')->default(false)->after('taxa_cash_out_fixa');
            $table->decimal('taxa_flexivel_valor_minimo', 10, 2)->nullable()->after('taxa_flexivel_ativa');
            $table->decimal('taxa_flexivel_fixa_baixo', 10, 2)->nullable()->after('taxa_flexivel_valor_minimo');
            $table->decimal('taxa_flexivel_percentual_alto', 10, 2)->nullable()->after('taxa_flexivel_fixa_baixo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'taxa_flexivel_ativa',
                'taxa_flexivel_valor_minimo',
                'taxa_flexivel_fixa_baixo',
                'taxa_flexivel_percentual_alto'
            ]);
        });
    }
};