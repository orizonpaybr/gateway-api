<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Corrige taxas globais padrão na tabela app.
     * Roda após add_taxa_fixa_pix_to_app_table (2025_09_19) para a coluna existir.
     * Padrão definido: R$ 1,00 para depósito (cash in) e saque (cash out),
     * a menos que o admin altere manualmente.
     */
    public function up(): void
    {
        $updateData = ['taxa_fixa_padrao' => 1.00];
        if (Schema::hasColumn('app', 'taxa_fixa_pix')) {
            $updateData['taxa_fixa_pix'] = 1.00;
        }

        DB::table('app')->update($updateData);

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `app` MODIFY `taxa_fixa_padrao` DECIMAL(10,2) NOT NULL DEFAULT 1.00');
        if (Schema::hasColumn('app', 'taxa_fixa_pix')) {
            DB::statement('ALTER TABLE `app` MODIFY `taxa_fixa_pix` DECIMAL(10,2) NOT NULL DEFAULT 1.00');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement('ALTER TABLE `app` MODIFY `taxa_fixa_padrao` DECIMAL(10,2) NOT NULL DEFAULT 5.00');
        if (Schema::hasColumn('app', 'taxa_fixa_pix')) {
            DB::statement('ALTER TABLE `app` MODIFY `taxa_fixa_pix` DECIMAL(10,2) NOT NULL DEFAULT 0.00');
        }
    }
};
