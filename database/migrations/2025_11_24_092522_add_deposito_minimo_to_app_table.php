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
            if (!Schema::hasColumn('app', 'deposito_minimo')) {
                $table->decimal('deposito_minimo', 10, 2)
                    ->default(1.00)
                    ->after('taxa_fixa_padrao')
                    ->comment('Valor mínimo permitido para depósitos padrão');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app', function (Blueprint $table) {
            if (Schema::hasColumn('app', 'deposito_minimo')) {
                $table->dropColumn('deposito_minimo');
            }
        });
    }
};
