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
            if (!Schema::hasColumn('app', 'saque_minimo')) {
                $table->decimal('saque_minimo', 10, 2)
                    ->default(1.00)
                    ->after('deposito_minimo')
                    ->comment('Valor mínimo permitido para saques padrão');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app', function (Blueprint $table) {
            if (Schema::hasColumn('app', 'saque_minimo')) {
                $table->dropColumn('saque_minimo');
            }
        });
    }
};
