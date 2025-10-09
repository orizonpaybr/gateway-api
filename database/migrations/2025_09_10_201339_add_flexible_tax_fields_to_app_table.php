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
            // Campos para sistema de taxas flexível
            $table->decimal('taxa_flexivel_valor_minimo', 10, 2)->default(15.00)->after('baseline');
            $table->decimal('taxa_flexivel_fixa_baixo', 10, 2)->default(1.00)->after('taxa_flexivel_valor_minimo');
            $table->decimal('taxa_flexivel_percentual_alto', 10, 2)->default(4.00)->after('taxa_flexivel_fixa_baixo');
            $table->boolean('taxa_flexivel_ativa')->default(false)->after('taxa_flexivel_percentual_alto');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app', function (Blueprint $table) {
            $table->dropColumn([
                'taxa_flexivel_valor_minimo',
                'taxa_flexivel_fixa_baixo',
                'taxa_flexivel_percentual_alto',
                'taxa_flexivel_ativa'
            ]);
        });
    }
};
