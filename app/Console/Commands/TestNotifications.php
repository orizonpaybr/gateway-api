<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PushNotificationService;
use App\Models\User;
use App\Models\PushToken;

class TestNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:test {user_id} {--type=deposit} {--amount=100.00}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testar envio de notificações push para um usuário';

    private $pushService;

    public function __construct(PushNotificationService $pushService)
    {
        parent::__construct();
        $this->pushService = $pushService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $userId = $this->argument('user_id');
        $type = $this->option('type');
        $amount = floatval($this->option('amount'));

        $this->info("Testando notificações para usuário: {$userId}");
        $this->info("Tipo: {$type}, Valor: R$ {$amount}");

        // Verificar se o usuário existe
        $user = User::where('username', $userId)->first();
        if (!$user) {
            $this->error("Usuário {$userId} não encontrado!");
            return 1;
        }

        // Verificar se o usuário tem tokens de push
        $tokens = PushToken::getActiveTokensForUser($userId);
        if ($tokens->isEmpty()) {
            $this->warn("Usuário {$userId} não possui tokens de push ativos!");
            $this->info("Para testar, registre um token primeiro usando o app mobile.");
            return 1;
        }

        $this->info("Encontrados {$tokens->count()} tokens ativos para o usuário");

        // Enviar notificação baseada no tipo
        $success = false;
        switch ($type) {
            case 'deposit':
                $success = $this->pushService->sendDepositNotification($userId, $amount, 'TEST_' . time());
                break;
            case 'withdraw':
                $success = $this->pushService->sendWithdrawNotification($userId, $amount, 'TEST_' . time());
                break;
            case 'commission':
                $success = $this->pushService->sendCommissionNotification($userId, $amount, 'Teste de comissão');
                break;
            case 'transfer':
                $success = $this->pushService->sendTransferNotification($userId, $amount, 'received', 'test_user');
                break;
            default:
                $this->error("Tipo de notificação inválido: {$type}");
                $this->info("Tipos válidos: deposit, withdraw, commission, transfer");
                return 1;
        }

        if ($success) {
            $this->info("✅ Notificação enviada com sucesso!");
            $this->info("Verifique o dispositivo do usuário para ver a notificação.");
        } else {
            $this->error("❌ Falha ao enviar notificação!");
        }

        return 0;
    }
}
