<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Corrige taxas globais padrão na tabela app.
     * Padrão definido: R$ 1,00 para depósito (cash in) e saque (cash out),
     * a menos que o admin altere manualmente.
     * Valores antigos incorretos: taxa_fixa_padrao 5.00, taxa_fixa_pix 0.00.
     */
    public function up(): void
    {
        // Atualiza registros existentes para o padrão correto (1.00)
        DB::table('app')->update([
            'taxa_fixa_padrao' => 1.00,
            'taxa_fixa_pix'    => 1.00,
        ]);

        // Altera o DEFAULT das colunas para 1.00 (novos ambientes/registros)
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `app` MODIFY `taxa_fixa_padrao` DECIMAL(10,2) NOT NULL DEFAULT 1.00');
            DB::statement('ALTER TABLE `app` MODIFY `taxa_fixa_pix` DECIMAL(10,2) NOT NULL DEFAULT 1.00');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `app` MODIFY `taxa_fixa_padrao` DECIMAL(10,2) NOT NULL DEFAULT 5.00');
            DB::statement('ALTER TABLE `app` MODIFY `taxa_fixa_pix` DECIMAL(10,2) NOT NULL DEFAULT 0.00');
        }
        // Não revertemos os dados dos registros (seria arbitrário)
    }
};
