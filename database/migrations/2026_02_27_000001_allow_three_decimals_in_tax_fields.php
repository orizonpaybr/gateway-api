<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Permite 3 casas decimais nas taxas (ex.: 0,015 = 1,5 centavos).
 * Taxas customizadas, taxas globais e comissão de afiliados.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $prefix = Schema::getConnection()->getTablePrefix();
        if ($driver === 'mysql') {
            $cols = [
                'taxa_fixa_padrao' => 'DECIMAL(10,3) NOT NULL DEFAULT 1.000',
                'taxa_fixa_pix' => 'DECIMAL(10,3) NOT NULL DEFAULT 1.000',
                'taxa_comissao_afiliado_padrao' => 'DECIMAL(10,3) NOT NULL DEFAULT 0.500',
            ];
            foreach ($cols as $col => $def) {
                if (Schema::hasColumn('app', $col)) {
                    \Illuminate\Support\Facades\DB::statement("ALTER TABLE `{$prefix}app` MODIFY `{$col}` {$def}");
                }
            }
        }

        // Tabela users: taxas personalizadas e comissão afiliado
        if ($driver === 'mysql') {
            $userCols = [
                'taxa_fixa_deposito' => 'DECIMAL(10,3) NOT NULL DEFAULT 0.000',
                'taxa_fixa_pix' => 'DECIMAL(10,3) NOT NULL DEFAULT 0.000',
                'taxa_comissao_afiliado' => 'DECIMAL(10,3) NULL',
            ];
            foreach ($userCols as $col => $def) {
                if (Schema::hasColumn('users', $col)) {
                    \Illuminate\Support\Facades\DB::statement("ALTER TABLE `{$prefix}users` MODIFY `{$col}` {$def}");
                }
            }
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $prefix = Schema::getConnection()->getTablePrefix();
        if ($driver === 'mysql') {
            $cols = [
                'taxa_fixa_padrao' => 'DECIMAL(10,2) NOT NULL DEFAULT 1.00',
                'taxa_fixa_pix' => 'DECIMAL(10,2) NOT NULL DEFAULT 1.00',
                'taxa_comissao_afiliado_padrao' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.50',
            ];
            foreach ($cols as $col => $def) {
                if (Schema::hasColumn('app', $col)) {
                    \Illuminate\Support\Facades\DB::statement("ALTER TABLE `{$prefix}app` MODIFY `{$col}` {$def}");
                }
            }
            $userCols = [
                'taxa_fixa_deposito' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                'taxa_fixa_pix' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                'taxa_comissao_afiliado' => 'DECIMAL(10,2) NULL',
            ];
            foreach ($userCols as $col => $def) {
                if (Schema::hasColumn('users', $col)) {
                    \Illuminate\Support\Facades\DB::statement("ALTER TABLE `{$prefix}users` MODIFY `{$col}` {$def}");
                }
            }
        }
    }
};
