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
            if (!Schema::hasColumn('app', 'limite_saque_mensal')) {
                $table->decimal('limite_saque_mensal', 12, 2)
                    ->default(50000.00)
                    ->after('saque_minimo')
                    ->comment('Limite máximo de saques mensais para pessoa física');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app', function (Blueprint $table) {
            if (Schema::hasColumn('app', 'limite_saque_mensal')) {
                $table->dropColumn('limite_saque_mensal');
            }
        });
    }
};
