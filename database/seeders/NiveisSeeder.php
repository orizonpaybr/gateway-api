<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NiveisSeeder extends Seeder
{
    /**
     * Seed da tabela de níveis com dados padrão
     */
    public function run(): void
    {
        // Verificar se já existem níveis
        $count = DB::table('niveis')->count();
        
        if ($count > 0) {
            $this->command->info('Níveis já existem na tabela. Pulando seed...');
            return;
        }
        
        $niveis = [
            [
                'nome' => 'Bronze',
                'cor' => '#CD7F32',
                'icone' => null,
                'minimo' => 0.00,
                'maximo' => 100000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nome' => 'Prata',
                'cor' => '#C0C0C0',
                'icone' => null,
                'minimo' => 100000.01,
                'maximo' => 500000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nome' => 'Ouro',
                'cor' => '#FFD700',
                'icone' => null,
                'minimo' => 500000.01,
                'maximo' => 1000000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nome' => 'Safira',
                'cor' => '#0F52BA',
                'icone' => null,
                'minimo' => 1000000.01,
                'maximo' => 5000000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nome' => 'Diamante',
                'cor' => '#B9F2FF',
                'icone' => null,
                'minimo' => 5000000.01,
                'maximo' => 10000000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];
        
        DB::table('niveis')->insert($niveis);
        
        // Ativar sistema de níveis
        DB::table('app')->where('id', 1)->update(['niveis_ativo' => true]);
        
        $this->command->info('Níveis criados com sucesso!');
    }
}


