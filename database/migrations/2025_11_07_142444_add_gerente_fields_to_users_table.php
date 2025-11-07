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
            // Adicionar campos de gerente se não existirem
            if (!Schema::hasColumn('users', 'gerente_id')) {
                $table->unsignedBigInteger('gerente_id')->nullable()->after('indicador_ref');
            }
            if (!Schema::hasColumn('users', 'gerente_percentage')) {
                $table->decimal('gerente_percentage', 5, 2)->default(0.00)->after('gerente_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'gerente_percentage')) {
                $table->dropColumn('gerente_percentage');
            }
            if (Schema::hasColumn('users', 'gerente_id')) {
                $table->dropColumn('gerente_id');
            }
        });
    }
};
