<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GamificationSeeder extends Seeder
{
    /**
     * Atualizar usuários com diferentes níveis de gamificação
     * Distribuir usuários entre Bronze, Prata, Ouro, Safira e Diamante
     */
    public function run(): void
    {
        // Verificar se os níveis existem
        $niveis = DB::table('niveis')->get()->keyBy('nome');
        
        if ($niveis->isEmpty()) {
            $this->command->warn('Nenhum nível encontrado. Execute NiveisSeeder primeiro.');
            return;
        }

        // Buscar usuários criados pelos seeds
        $users = DB::table('users')
            ->whereIn('username', ['admin', 'gerente1', 'gerente2', 'usuario1', 'usuario2'])
            ->get();

        if ($users->isEmpty()) {
            $this->command->warn('Nenhum usuário encontrado. Execute UsersSeeder primeiro.');
            return;
        }

        // Distribuição de níveis baseada no volume transacional
        $niveisDistribuicao = [
            'admin' => [
                'nivel' => 'Bronze',
                'volume' => 50000, // R$ 50k - Bronze (0 - 100k)
                'saldo' => 25000,
            ],
            'gerente1' => [
                'nivel' => 'Safira',
                'volume' => 3500000, // R$ 3.5M - Safira (1M - 5M)
                'saldo' => 1800000,
            ],
            'gerente2' => [
                'nivel' => 'Diamante',
                'volume' => 8500000, // R$ 8.5M - Diamante (5M - 10M)
                'saldo' => 4200000,
            ],
            'usuario1' => [
                'nivel' => 'Prata',
                'volume' => 280000, // R$ 280k - Prata (100k - 500k)
                'saldo' => 145000,
            ],
            'usuario2' => [
                'nivel' => 'Ouro',
                'volume' => 750000, // R$ 750k - Ouro (500k - 1M)
                'saldo' => 385000,
            ],
        ];

        foreach ($users as $user) {
            if (!isset($niveisDistribuicao[$user->username])) {
                continue;
            }

            $distribuicao = $niveisDistribuicao[$user->username];
            $nivel = $niveis->get($distribuicao['nivel']);

            if (!$nivel) {
                $this->command->warn("Nível {$distribuicao['nivel']} não encontrado para {$user->username}");
                continue;
            }

            // Atualizar usuário
            DB::table('users')->where('id', $user->id)->update([
                'volume_transacional' => $distribuicao['volume'],
                'saldo' => $distribuicao['saldo'],
                'updated_at' => now(),
            ]);

            $this->command->info(
                sprintf(
                    "✅ %s (%s) -> Nível: %s | Volume: R$ %s | Saldo: R$ %s",
                    $user->name,
                    $user->username,
                    $distribuicao['nivel'],
                    number_format($distribuicao['volume'], 2, ',', '.'),
                    number_format($distribuicao['saldo'], 2, ',', '.')
                )
            );

            // Criar notificação de nível conquistado
            $this->createLevelUpNotification($user->user_id, $distribuicao['nivel']);
        }

        $this->command->info('');
        $this->command->info('📊 Resumo da Gamificação:');
        $this->command->info('  🥉 Bronze: admin (R$ 50k)');
        $this->command->info('  🥈 Prata: usuario1 (R$ 280k)');
        $this->command->info('  🥇 Ouro: usuario2 (R$ 750k)');
        $this->command->info('  💎 Safira: gerente1 (R$ 3.5M)');
        $this->command->info('  💎 Diamante: gerente2 (R$ 8.5M)');
    }

    /**
     * Criar notificação de nível conquistado
     */
    private function createLevelUpNotification(string $userId, string $nivelNome): void
    {
        $mensagens = [
            'Bronze' => [
                'title' => '🥉 Bem-vindo ao Nível Bronze!',
                'body' => 'Parabéns! Você deu o primeiro passo na sua jornada. Continue assim e veja sua evolução!',
            ],
            'Prata' => [
                'title' => '🥈 Nível Prata Desbloqueado!',
                'body' => 'Excelente evolução! Você está colhendo os frutos do seu esforço. Parabéns pela conquista!',
            ],
            'Ouro' => [
                'title' => '🥇 Nível Ouro Conquistado!',
                'body' => 'Impressionante! Sua persistência está dando resultados. Você está entre os melhores!',
            ],
            'Safira' => [
                'title' => '💎 Nível Safira Alcançado!',
                'body' => 'Extraordinário! Você é um vencedor de verdade. Sua determinação inspira!',
            ],
            'Diamante' => [
                'title' => '💎 Nível Diamante - Topo!',
                'body' => 'Parabéns! Você alcançou o ápice da Jornada Orizon! Sua dedicação é exemplar!',
            ],
        ];

        $mensagem = $mensagens[$nivelNome] ?? [
            'title' => 'Novo Nível Alcançado!',
            'body' => "Parabéns! Você alcançou o nível {$nivelNome}!",
        ];

        DB::table('notifications')->insert([
            'user_id' => $userId,
            'type' => 'level_up',
            'title' => $mensagem['title'],
            'body' => $mensagem['body'],
            'data' => json_encode([
                'nivel' => $nivelNome,
                'action_url' => '/gamification',
                'priority' => 'high',
            ]),
            'read_at' => null, // Não lida para aparecer no dropdown
            'sent_at' => now(),
            'push_sent' => false,
            'local_sent' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}





