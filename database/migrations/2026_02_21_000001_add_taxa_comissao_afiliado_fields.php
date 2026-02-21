<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Adiciona taxa global de comissão de afiliado na tabela app
        Schema::table('app', function (Blueprint $table) {
            $table->decimal('taxa_comissao_afiliado_padrao', 10, 2)->default(0.50)->after('taxa_fixa_pix');
        });

        // Adiciona campos de comissão personalizada por afiliado na tabela users
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('taxa_comissao_afiliado', 10, 2)->nullable()->after('saldo_afiliado');
            $table->boolean('comissao_afiliado_personalizada')->default(false)->after('taxa_comissao_afiliado');
        });
    }

    public function down(): void
    {
        Schema::table('app', function (Blueprint $table) {
            $table->dropColumn('taxa_comissao_afiliado_padrao');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['taxa_comissao_afiliado', 'comissao_afiliado_personalizada']);
        });
    }
};
