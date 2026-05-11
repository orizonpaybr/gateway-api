<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Colunas PIX por usuário alinhadas ao model User e AdminUserService.
     * (Os campos de cartão+boleto foram adicionados em 2025_10_08; estes nunca tinham migração.)
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'preferred_adquirente')) {
                $table->string('preferred_adquirente')->nullable();
            }
            if (!Schema::hasColumn('users', 'adquirente_override')) {
                $table->boolean('adquirente_override')->default(false);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $cols = array_values(array_filter([
            Schema::hasColumn('users', 'preferred_adquirente') ? 'preferred_adquirente' : null,
            Schema::hasColumn('users', 'adquirente_override') ? 'adquirente_override' : null,
        ]));
        if ($cols !== []) {
            Schema::table('users', function (Blueprint $table) use ($cols) {
                $table->dropColumn($cols);
            });
        }
    }
};
