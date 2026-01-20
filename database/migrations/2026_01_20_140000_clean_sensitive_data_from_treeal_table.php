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
        DB::table('treeal')->update([
            'certificate_password' => null,
            'client_id' => null,
            'client_secret' => null,
            'qrcodes_client_id' => null,
            'qrcodes_client_secret' => null,
            'pix_key_secondary' => null,
            'certificate_path' => null, // Caminho pode ficar, mas vamos limpar também
            'updated_at' => now(),
        ]);
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
