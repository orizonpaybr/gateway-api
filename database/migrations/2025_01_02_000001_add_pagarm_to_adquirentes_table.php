<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Verificar se a tabela adquirentes existe antes de inserir
        if (Schema::hasTable('adquirentes')) {
            // Verificar se já existe para evitar duplicação
            $exists = DB::table('adquirentes')->where('referencia', 'pagarm')->exists();
            
            if (!$exists) {
                // Preparar dados base (campos que sempre existem)
                $data = [
                    'adquirente' => 'pagarm',
                    'status' => 0,
                    'url' => 'https://api.pagarm.com.br/v1',
                    'referencia' => 'pagarm',
                    'created_at' => now(),
                    'updated_at' => now()
                ];
                
                // Adicionar campos opcionais apenas se existirem na tabela
                if (Schema::hasColumn('adquirentes', 'is_default')) {
                    $data['is_default'] = 0;
                }
                
                if (Schema::hasColumn('adquirentes', 'is_default_card_billet')) {
                    $data['is_default_card_billet'] = 0;
                }
                
                // Adicionar PagArm na tabela adquirentes
                DB::table('adquirentes')->insert($data);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('adquirentes')->where('referencia', 'pagarm')->delete();
    }
};


