<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class NotificationsSeeder extends Seeder
{
    /**
     * Criar notificações de teste
     * 50 notificações (algumas lidas, outras não) para testar filtros e paginação
     */
    public function run(): void
    {
        // Buscar IDs de usuários criados
        $userIds = DB::table('users')
            ->whereIn('username', ['gerente1', 'gerente2', 'usuario1', 'usuario2'])
            ->pluck('user_id')
            ->toArray();
        
        if (empty($userIds)) {
            $this->command->warn('Nenhum usuário encontrado. Execute UsersSeeder primeiro.');
            return;
        }

        $notifications = [];
        
        // Tipos de notificação
        $types = [
            'transaction' => 'Transação',
            'withdrawal' => 'Saque',
            'deposit' => 'Depósito',
            'security' => 'Segurança',
            'system' => 'Sistema',
            'infraction' => 'Infração',
            'level_up' => 'Gamificação'
        ];

        // Templates de notificações
        $templates = [
            'transaction' => [
                ['title' => 'Transação Aprovada', 'body' => 'Sua transação de R$ {value} foi aprovada com sucesso!'],
                ['title' => 'Transação Pendente', 'body' => 'Transação de R$ {value} está aguardando confirmação.'],
                ['title' => 'Transação Cancelada', 'body' => 'A transação de R$ {value} foi cancelada.'],
            ],
            'withdrawal' => [
                ['title' => 'Saque Aprovado', 'body' => 'Seu saque de R$ {value} foi processado com sucesso!'],
                ['title' => 'Saque Pendente', 'body' => 'Saque de R$ {value} aguardando aprovação.'],
                ['title' => 'Saque Rejeitado', 'body' => 'Saque de R$ {value} foi rejeitado. Entre em contato com o suporte.'],
            ],
            'deposit' => [
                ['title' => 'Depósito Confirmado', 'body' => 'Depósito de R$ {value} foi creditado em sua conta!'],
                ['title' => 'Depósito Recebido', 'body' => 'Recebemos seu depósito de R$ {value}. Processando...'],
                ['title' => 'Falha no Depósito', 'body' => 'Não foi possível processar o depósito de R$ {value}.'],
            ],
            'security' => [
                ['title' => 'Novo Login Detectado', 'body' => 'Um novo login foi detectado em sua conta. Se não foi você, altere sua senha imediatamente.'],
                ['title' => 'Senha Alterada', 'body' => 'Sua senha foi alterada com sucesso.'],
                ['title' => 'Tentativa de Acesso Bloqueada', 'body' => 'Detectamos uma tentativa de acesso suspeita que foi bloqueada.'],
                ['title' => '2FA Ativado', 'body' => 'Autenticação de dois fatores foi ativada em sua conta.'],
            ],
            'system' => [
                ['title' => 'Manutenção Programada', 'body' => 'Sistema entrará em manutenção no dia 30/12 às 02h.'],
                ['title' => 'Nova Funcionalidade', 'body' => 'Confira a nova funcionalidade de relatórios avançados!'],
                ['title' => 'Atualização de Termos', 'body' => 'Nossos termos de uso foram atualizados. Revise as mudanças.'],
                ['title' => 'Bem-vindo ao Coratri', 'body' => 'Obrigado por se cadastrar! Explore todas as funcionalidades.'],
            ],
            'infraction' => [
                ['title' => 'Nova Infração Registrada', 'body' => 'Uma infração foi registrada em sua conta. Protocolo: {protocol}'],
                ['title' => 'Infração Resolvida', 'body' => 'A infração {protocol} foi resolvida.'],
                ['title' => 'Chargeback Recebido', 'body' => 'Um chargeback de R$ {value} foi registrado.'],
            ],
            'level_up' => [
                ['title' => '🎉 Parabéns! Nível Bronze', 'body' => 'Você alcançou o nível Bronze! Continue depositando para subir de nível.'],
                ['title' => '🥈 Nível Prata Desbloqueado!', 'body' => 'Incrível! Você está no nível Prata agora!'],
                ['title' => '🥇 Nível Ouro Conquistado!', 'body' => 'Você chegou ao nível Ouro! Aproveite seus benefícios exclusivos.'],
                ['title' => '💎 Nível Safira Alcançado!', 'body' => 'Extraordinário! Você está no nível Safira!'],
            ],
        ];

        // Criar 50 notificações
        for ($i = 1; $i <= 50; $i++) {
            $userId = $userIds[array_rand($userIds)];
            $type = array_rand($types);
            $template = $templates[$type][array_rand($templates[$type])];
            
            $daysAgo = rand(0, 30);
            $hoursAgo = rand(0, 23);
            $minutesAgo = rand(0, 59);
            $createdAt = Carbon::now()->subDays($daysAgo)->subHours($hoursAgo)->subMinutes($minutesAgo);
            
            // 60% das notificações são lidas
            $isRead = rand(1, 100) <= 60;
            $readAt = $isRead ? $createdAt->copy()->addHours(rand(1, 48)) : null;
            
            $value = $this->randomAmount(50, 5000);
            $protocol = 'PROT-' . strtoupper(uniqid());
            
            // Substituir variáveis no template
            $body = str_replace(
                ['{value}', '{protocol}'],
                [number_format($value, 2, ',', '.'), $protocol],
                $template['body']
            );

            $data = [
                'value' => $value,
                'protocol' => $protocol,
                'type' => $type,
                'priority' => ['low', 'medium', 'high'][rand(0, 2)],
                'action_url' => null,
            ];

            // Adicionar URL de ação para alguns tipos
            if (in_array($type, ['transaction', 'withdrawal', 'deposit'])) {
                $data['action_url'] = '/extrato';
            } elseif ($type === 'infraction') {
                $data['action_url'] = '/pix/infracoes';
            } elseif ($type === 'level_up') {
                $data['action_url'] = '/gamification';
            }

            $notifications[] = [
                'user_id' => $userId,
                'type' => $type,
                'title' => $template['title'],
                'body' => $body,
                'data' => json_encode($data),
                'read_at' => $readAt,
                'sent_at' => $createdAt,
                'push_sent' => rand(0, 1) ? true : false,
                'local_sent' => true,
                'created_at' => $createdAt,
                'updated_at' => $readAt ?? $createdAt,
            ];
        }

        // Limpar notificações de seed anteriores
        DB::table('notifications')
            ->whereIn('user_id', $userIds)
            ->where('body', 'like', '%seed%')
            ->orWhere('title', 'like', '%Test%')
            ->delete();
        
        // Inserir em lotes
        DB::table('notifications')->insert($notifications);
        
        // Contar não lidas por usuário
        foreach ($userIds as $userId) {
            $unreadCount = DB::table('notifications')
                ->where('user_id', $userId)
                ->whereNull('read_at')
                ->count();
            
            $this->command->info("Usuário {$userId}: {$unreadCount} notificações não lidas");
        }
        
        $this->command->info('50 notificações criadas.');
    }

    /**
     * Gerar valor aleatório
     */
    private function randomAmount(float $min, float $max): float
    {
        return round(mt_rand($min * 100, $max * 100) / 100, 2);
    }
}





