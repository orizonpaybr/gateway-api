<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Executar seeders na ordem correta
        $this->call([
            // 1. Criar níveis de gamificação
            NiveisSeeder::class,
            
            // 2. Criar usuários (admin, gerentes e usuários comuns)
            AdminUserSeeder::class,
            UsersSeeder::class,
            
            // 3. Completar dados da conta dos usuários
            UserAccountDataSeeder::class,
            
            // 4. Criar transações (depósitos e saques)
            TransactionsSeeder::class,
            
            // 5. Criar QR Codes (checkout_build)
            QRCodesSeeder::class,
            
            // 6. Criar transações pendentes
            PendingTransactionsSeeder::class,
            
            // 7. Criar infrações PIX
            PixInfracoesSeeder::class,
            
            // 8. Criar notificações
            NotificationsSeeder::class,
            
            // 9. Configurar gamificação (níveis dos usuários)
            GamificationSeeder::class,
            
            // 10. Popular dados do dashboard com valores altos
            DashboardDataSeeder::class,
            
            // 11. Criar saques para aprovação (admin)
            AdminWithdrawalsSeeder::class,
            
            // 12. Popular seções do Financeiro
            AdminFinancialSeeder::class,
        ]);
        
        $this->command->info('');
        $this->command->info('========================================');
        $this->command->info('🎉 Todos os seeds foram executados com sucesso!');
        $this->command->info('========================================');
        $this->command->info('');
        $this->command->info('📝 Resumo dos dados criados:');
        $this->command->info('');
        $this->command->info('👥 USUÁRIOS:');
        $this->command->info('  • 1 Admin (admin@exemplo.com / teste123)');
        $this->command->info('  • 1 Usuário de teste (teste@exemplo.com / teste123)');
        $this->command->info('  • 2 Gerentes (gerente1@orizon.com, gerente2@orizon.com / teste123)');
        $this->command->info('  • 2 Usuários comuns (usuario1@exemplo.com, usuario2@exemplo.com / teste123)');
        $this->command->info('');
        $this->command->info('💰 TRANSAÇÕES:');
        $this->command->info('  • 30 Depósitos normais');
        $this->command->info('  • 20 Depósitos de alto valor (dashboard)');
        $this->command->info('  • 30 Saques');
        $this->command->info('  • 30 Transações Pendentes');
        $this->command->info('');
        $this->command->info('📊 OUTROS DADOS:');
        $this->command->info('  • 30 QR Codes (checkout_build)');
        $this->command->info('  • 30 Infrações PIX');
        $this->command->info('  • 50 Notificações');
        $this->command->info('  • 5 Níveis de gamificação');
        $this->command->info('');
        $this->command->info('🎮 GAMIFICAÇÃO:');
        $this->command->info('  🥉 Bronze: admin (R$ 50k)');
        $this->command->info('  🥈 Prata: usuario1 (R$ 280k)');
        $this->command->info('  🥇 Ouro: usuario2 (R$ 750k)');
        $this->command->info('  💎 Safira: gerente1 (R$ 3.5M)');
        $this->command->info('  💎 Diamante: gerente2 (R$ 8.5M)');
        $this->command->info('');
        $this->command->info('🔧 CONFIGURAÇÕES:');
        $this->command->info('  • Taxas personalizadas: Ativas para gerentes');
        $this->command->info('  • Webhooks: Configurados para gerentes');
        $this->command->info('  • 2FA: Aleatório (alguns ativos, outros não)');
        $this->command->info('');
        $this->command->info('👨‍💼 DASHBOARD ADMINISTRATIVO:');
        $this->command->info('  • Cards com valores acima de R$ 1 milhão');
        $this->command->info('  • 30 saques para aprovação');
        $this->command->info('  • 40 depósitos para relatório de entradas');
        $this->command->info('  • 40 saques para relatório de saídas');
        $this->command->info('  • Dados de carteiras atualizados');
        $this->command->info('');
        $this->command->info('🔑 Todos os usuários têm a senha: teste123');
        $this->command->info('');
    }
}
