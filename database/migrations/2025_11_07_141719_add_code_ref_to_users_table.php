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
            // Adicionar code_ref se não existir
            if (!Schema::hasColumn('users', 'code_ref')) {
                $table->string('code_ref')->nullable()->after('indicador_ref');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'code_ref')) {
                $table->dropColumn('code_ref');
            }
        });
    }
};
