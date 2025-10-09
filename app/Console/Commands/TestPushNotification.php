<?php

namespace App\Console\Commands;

use App\Services\PushNotificationService;
use App\Models\User;
use App\Models\PushToken;
use Illuminate\Console\Command;

class TestPushNotification extends Command
{
    protected $signature = 'push:test {user_id} {--type=deposit : Tipo de notificação (deposit, withdraw, commission)}';
    protected $description = 'Testar envio de notificações push para um usuário';

    private $pushService;

    public function __construct(PushNotificationService $pushService)
    {
        parent::__construct();
        $this->pushService = $pushService;
    }

    public function handle()
    {
        $userId = $this->argument('user_id');
        $type = $this->option('type');

        $user = User::where('user_id', $userId)->first();
        
        if (!$user) {
            $this->error("❌ Usuário {$userId} não encontrado!");
            return 1;
        }

        $this->info("👤 Testando notificação para: {$user->username} ({$userId})");

        // Verificar tokens do usuário
        $tokens = PushToken::getActiveTokensForUser($userId);
        
        if ($tokens->isEmpty()) {
            $this->error("❌ Usuário não possui tokens de push ativos!");
            $this->info("💡 Faça login no app mobile para registrar o token.");
            return 1;
        }

        $this->info("📱 Tokens ativos encontrados: " . $tokens->count());
        foreach ($tokens as $token) {
            $this->line("   - " . substr($token->token, 0, 40) . "...");
        }

        // Enviar notificação de teste
        $this->info("\n📤 Enviando notificação de teste...");
        
        $result = false;
        
        switch ($type) {
            case 'deposit':
                $result = $this->pushService->sendDepositNotification($userId, 100.50, 'TEST_' . time());
                break;
            case 'withdraw':
                $result = $this->pushService->sendWithdrawNotification($userId, 50.25, 'TEST_' . time());
                break;
            case 'commission':
                $result = $this->pushService->sendCommissionNotification($userId, 25.00, 'Teste de comissão');
                break;
            default:
                $this->error("Tipo de notificação inválido: {$type}");
                return 1;
        }

        if ($result) {
            $this->info("✅ Notificação enviada com sucesso!");
            $this->info("📱 Verifique seu celular para confirmar o recebimento.");
        } else {
            $this->error("❌ Falha ao enviar notificação!");
            $this->error("🔍 Verifique os logs em storage/logs/laravel.log para mais detalhes.");
        }

        return 0;
    }
}

