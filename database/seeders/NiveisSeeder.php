<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NiveisSeeder extends Seeder
{
    /**
     * Seed da tabela de níveis com dados padrão da Jornada Orizon
     * 
     * Cria ou atualiza os 5 níveis de gamificação:
     * - Bronze: R$ 0,00 - R$ 100.000,00
     * - Prata: R$ 100.000,01 - R$ 500.000,00
     * - Ouro: R$ 500.000,01 - R$ 1.000.000,00
     * - Safira: R$ 1.000.000,01 - R$ 5.000.000,00
     * - Diamante: R$ 5.000.000,01 - R$ 10.000.000,00
     */
    public function run(): void
    {
        $niveis = [
            [
                'nome' => 'Bronze',
                'cor' => '#CD7F32', // Bronze
                'icone' => null,
                'minimo' => 0.00,
                'maximo' => 100000.00,
            ],
            [
                'nome' => 'Prata',
                'cor' => '#C0C0C0', // Silver
                'icone' => null,
                'minimo' => 100000.01,
                'maximo' => 500000.00,
            ],
            [
                'nome' => 'Ouro',
                'cor' => '#FFD700', // Gold
                'icone' => null,
                'minimo' => 500000.01,
                'maximo' => 1000000.00,
            ],
            [
                'nome' => 'Safira',
                'cor' => '#0F52BA', // Sapphire Blue
                'icone' => null,
                'minimo' => 1000000.01,
                'maximo' => 5000000.00,
            ],
            [
                'nome' => 'Diamante',
                'cor' => '#B9F2FF', // Diamond Blue
                'icone' => null,
                'minimo' => 5000000.01,
                'maximo' => 10000000.00,
            ],
        ];
        
        $created = 0;
        $updated = 0;
        
        foreach ($niveis as $nivel) {
            $existing = DB::table('niveis')->where('nome', $nivel['nome'])->first();
            
            if ($existing) {
                // Atualizar nível existente
                DB::table('niveis')
                    ->where('nome', $nivel['nome'])
                    ->update([
                        'cor' => $nivel['cor'],
                        'icone' => $nivel['icone'],
                        'minimo' => $nivel['minimo'],
                        'maximo' => $nivel['maximo'],
                        'updated_at' => now(),
                    ]);
                $updated++;
                $this->command->info("  ✓ Nível '{$nivel['nome']}' atualizado");
            } else {
                // Criar novo nível
                DB::table('niveis')->insert([
                    'nome' => $nivel['nome'],
                    'cor' => $nivel['cor'],
                    'icone' => $nivel['icone'],
                    'minimo' => $nivel['minimo'],
                    'maximo' => $nivel['maximo'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created++;
                $this->command->info("  ✓ Nível '{$nivel['nome']}' criado");
            }
        }
        
        // Garantir que a tabela app existe e ativar sistema de níveis
        if (DB::getSchemaBuilder()->hasTable('app')) {
            $appExists = DB::table('app')->where('id', 1)->exists();
            
            if (!$appExists) {
                // Criar registro de configuração se não existir
                DB::table('app')->insert([
                    'id' => 1,
                    'niveis_ativo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->command->info('  ✓ Registro de configuração da aplicação criado');
            } else {
                // Ativar sistema de níveis
                DB::table('app')->where('id', 1)->update(['niveis_ativo' => true]);
                $this->command->info('  ✓ Sistema de níveis ativado');
            }
        }
        
        $this->command->info('');
        $this->command->info("✅ Níveis de gamificação configurados!");
        $this->command->info("   • {$created} níveis criados");
        $this->command->info("   • {$updated} níveis atualizados");
        $this->command->info('');
        $this->command->info('📊 Níveis configurados:');
        foreach ($niveis as $nivel) {
            $this->command->info(sprintf(
                "   • %s: R$ %s - R$ %s",
                $nivel['nome'],
                number_format($nivel['minimo'], 2, ',', '.'),
                number_format($nivel['maximo'], 2, ',', '.')
            ));
        }
        $this->command->info('');
    }
}


