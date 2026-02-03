<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Remove dados sensíveis da tabela treeal, pois agora estão no .env
     */
    public function up(): void
    {
        // Limpar dados sensíveis, mantendo apenas configurações não sensíveis
        // Verificar se as colunas existem antes de tentar atualizar
        // (elas podem já ter sido removidas pela migration anterior)
        
        $columnsToClean = [];
        
        if (Schema::hasColumn('treeal', 'certificate_password')) {
            $columnsToClean['certificate_password'] = null;
        }
        if (Schema::hasColumn('treeal', 'client_id')) {
            $columnsToClean['client_id'] = null;
        }
        if (Schema::hasColumn('treeal', 'client_secret')) {
            $columnsToClean['client_secret'] = null;
        }
        if (Schema::hasColumn('treeal', 'qrcodes_client_id')) {
            $columnsToClean['qrcodes_client_id'] = null;
        }
        if (Schema::hasColumn('treeal', 'qrcodes_client_secret')) {
            $columnsToClean['qrcodes_client_secret'] = null;
        }
        if (Schema::hasColumn('treeal', 'pix_key_secondary')) {
            $columnsToClean['pix_key_secondary'] = null;
        }
        if (Schema::hasColumn('treeal', 'certificate_path')) {
            $columnsToClean['certificate_path'] = null;
        }
        
        // Só atualiza se houver colunas para limpar
        if (!empty($columnsToClean)) {
            $columnsToClean['updated_at'] = now();
            DB::table('treeal')->update($columnsToClean);
        }
    }

    /**
     * Reverse the migrations.
     * 
     * Não há como reverter - dados foram removidos
     */
    public function down(): void
    {
        // Não é possível reverter - dados sensíveis foram removidos
        // Será necessário reconfigurar manualmente se necessário
    }
};
