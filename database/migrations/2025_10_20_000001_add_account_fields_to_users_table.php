<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Informações cadastrais
            if (!Schema::hasColumn('users', 'area_atuacao')) {
                $table->string('area_atuacao')->nullable();
            }
            if (!Schema::hasColumn('users', 'status_cadastro')) {
                $table->string('status_cadastro')->nullable();
            }

            // Retenção
            if (!Schema::hasColumn('users', 'retencao_valor')) {
                $table->decimal('retencao_valor', 10, 2)->default(0.00);
            }
            if (!Schema::hasColumn('users', 'retencao_taxa')) {
                $table->decimal('retencao_taxa', 5, 2)->default(0.00);
            }

            // Taxas de afiliado
            if (!Schema::hasColumn('users', 'taxa_fixa_afiliado')) {
                $table->decimal('taxa_fixa_afiliado', 10, 2)->default(0.00);
            }
            if (!Schema::hasColumn('users', 'taxa_percentual_afiliado')) {
                $table->decimal('taxa_percentual_afiliado', 5, 2)->default(0.00);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'area_atuacao')) {
                $table->dropColumn('area_atuacao');
            }
            if (Schema::hasColumn('users', 'status_cadastro')) {
                $table->dropColumn('status_cadastro');
            }
            if (Schema::hasColumn('users', 'retencao_valor')) {
                $table->dropColumn('retencao_valor');
            }
            if (Schema::hasColumn('users', 'retencao_taxa')) {
                $table->dropColumn('retencao_taxa');
            }
            if (Schema::hasColumn('users', 'taxa_fixa_afiliado')) {
                $table->dropColumn('taxa_fixa_afiliado');
            }
            if (Schema::hasColumn('users', 'taxa_percentual_afiliado')) {
                $table->dropColumn('taxa_percentual_afiliado');
            }
        });
    }
};


