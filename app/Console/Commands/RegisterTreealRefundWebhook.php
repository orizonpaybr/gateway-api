<?php

namespace App\Console\Commands;

use App\Services\TreealService;
use Illuminate\Console\Command;


//Registra o webhook "refund" (estornos) na Accounts API v2 da Treeal/ONZ.
class RegisterTreealRefundWebhook extends Command
{
    protected $signature = 'treeal:register-refund-webhook
                            {--url= : URL completa do webhook (padrão: APP_URL/treeal/webhook)}';

    protected $description = 'Registra o webhook refund (estornos) na Treeal/ONZ Accounts API v2';

    public function handle(): int
    {
        $service = app(TreealService::class);

        if (!$service->isActive()) {
            $this->error('Treeal não está configurado ou ativo. Verifique o .env e a tabela treeal.');
            return 1;
        }

        $webhookUrl = $this->option('url')
            ?: rtrim(config('app.url'), '/') . '/treeal/webhook';

        $this->info("Registrando webhook refund: {$webhookUrl}");

        $appUrl = rtrim(config('app.url'), '/');
        if (!str_starts_with($webhookUrl, $appUrl)) {
            $this->warn("A URL ({$webhookUrl}) não começa com APP_URL ({$appUrl}).");
            if (!$this->confirm('Continuar mesmo assim?', false)) {
                return 1;
            }
        }

        try {
            $result = $service->registerRefundWebhook($webhookUrl);

            if ($result['success']) {
                $this->info('Webhook refund registrado com sucesso!');
                $this->line('   URL: ' . $webhookUrl);
                if (!empty($result['webhook_id'])) {
                    $this->line('   ID:  ' . $result['webhook_id']);
                }
                $this->newLine();
                $this->line('Para listar todos os webhooks: php artisan treeal:register-cashout-webhook --list');
            } else {
                $this->error('Falha ao registrar webhook: ' . ($result['message'] ?? 'Erro desconhecido'));
                return 1;
            }
        } catch (\Exception $e) {
            $this->error('Exceção: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
