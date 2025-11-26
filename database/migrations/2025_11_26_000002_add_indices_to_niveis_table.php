<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adiciona índices para otimizar queries de gamificação:
     * - Index em 'minimo' e 'maximo' para buscas de intervalo
     * - Composite index (minimo, maximo) para queries de overlap
     */
    public function up(): void
    {
        Schema::table('niveis', function (Blueprint $table) {
            // Índice individual para minimo (usado em WHERE minimo <= X)
            $table->index('minimo', 'niveis_minimo_index');

            // Índice individual para maximo (usado em WHERE maximo >= X)
            $table->index('maximo', 'niveis_maximo_index');

            // Índice composto para queries de overlap
            // Otimiza: WHERE (minimo BETWEEN X AND Y) OR (maximo BETWEEN X AND Y)
            $table->index(['minimo', 'maximo'], 'niveis_minimo_maximo_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('niveis', function (Blueprint $table) {
            // Os nomes aqui precisam bater com os definidos no up()
            $table->dropIndex('niveis_minimo_index');
            $table->dropIndex('niveis_maximo_index');
            $table->dropIndex('niveis_minimo_maximo_index');
        });
    }
};

