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
        // Tornar o campo limite_saque_automatico anulável
        Schema::table('app', function (Blueprint $table) {
            // Em MySQL, para usar change() é necessário que a coluna já exista
            if (Schema::hasColumn('app', 'limite_saque_automatico')) {
                $table->decimal('limite_saque_automatico', 10, 2)
                    ->nullable()
                    ->default(null)
                    ->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverter para NOT NULL com default 1000.00 (valor anterior das migrations do projeto)
        Schema::table('app', function (Blueprint $table) {
            if (Schema::hasColumn('app', 'limite_saque_automatico')) {
                $table->decimal('limite_saque_automatico', 10, 2)
                    ->default(1000.00)
                    ->nullable(false)
                    ->change();
            }
        });
    }
};

