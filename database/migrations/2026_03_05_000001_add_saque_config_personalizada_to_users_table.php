<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('saque_config_personalizada')->default(false)->after('comissao_afiliado_personalizada');
            $table->boolean('saque_automatico_usuario')->nullable()->after('saque_config_personalizada');
            $table->decimal('limite_saque_automatico_usuario', 10, 2)->nullable()->after('saque_automatico_usuario');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'saque_config_personalizada',
                'saque_automatico_usuario',
                'limite_saque_automatico_usuario',
            ]);
        });
    }
};
