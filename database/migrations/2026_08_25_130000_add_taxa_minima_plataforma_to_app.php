<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Piso mínimo de taxa da PLATAFORMA (regra própria da Coratri, editável pelo admin),
 * movido do .env para a tabela `app`. Independente de adquirente.
 *
 * O código lê o valor com fallback pra config('plataforma.*'), então funciona mesmo
 * antes desta migration rodar — sem janela de quebra na ordem do deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app', function (Blueprint $table) {
            if (! Schema::hasColumn('app', 'taxa_minima_fixa')) {
                $table->decimal('taxa_minima_fixa', 10, 4)->default(0.05)->after('taxa_comissao_afiliado_padrao');
            }
            if (! Schema::hasColumn('app', 'taxa_minima_percentual')) {
                $table->decimal('taxa_minima_percentual', 6, 3)->default(0)->after('taxa_minima_fixa');
            }
        });
    }

    public function down(): void
    {
        Schema::table('app', function (Blueprint $table) {
            foreach (['taxa_minima_fixa', 'taxa_minima_percentual'] as $col) {
                if (Schema::hasColumn('app', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
