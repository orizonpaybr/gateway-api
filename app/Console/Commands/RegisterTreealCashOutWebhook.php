<?php

namespace App\Console\Commands;

use App\Services\TreealService;
use Illuminate\Console\Command;

/**
 * Registra a URL de webhook de Cash Out (transfer + cashout) na Accounts API da Treeal/ONZ.
 *
 *
 * Uso:
 *   php artisan treeal:register-cashout-webhook          → registra com URL padrão
 *   php artisan treeal:register-cashout-webhook --list   → lista webhooks registrados
 */
class RegisterTreealCashOutWebhook extends Command
{
    protected $signature = 'treeal:register-cashout-webhook
                            {--url=    : URL completa do webhook (padrão: APP_URL/treeal/webhook)}
                            {--list    : Lista webhooks já registrados na Accounts API}';

    protected $description = 'Registra ou lista os webhooks de Cash Out (saques) na Treeal/ONZ Accounts API';

    public function handle(): int
    {
        $service = app(TreealService::class);

        if (!$service->isActive()) {
            $this->error('Treeal não está configurado ou ativo. Verifique o .env e a tabela treeal.');
            return 1;
        }

        if ($this->option('list')) {
            $this->info('Consultando webhooks registrados na Accounts API...');
            try {
                $result = $service->listCashOutWebhooks();
                $data   = $result['data'] ?? [];

                if (empty($data)) {
                    $this->warn('Nenhum webhook registrado ainda.');
                    $this->line('Execute sem --list para registrar.');
                } else {
                    $this->info('Webhooks registrados:');
                    foreach ((array) $data as $webhook) {
                        $id      = $webhook['id'] ?? '-';
                        $uri     = $webhook['uri'] ?? '-';
                        $type    = $webhook['type'] ?? '-';
                        $enabled = isset($webhook['enabled']) ? ($webhook['enabled'] ? 'ativo' : 'inativo') : '-';
                        $this->line("  [{$id}] {$type} → {$uri} ({$enabled})");
                    }
                }
            } catch (\Exception $e) {
                $this->error('Erro: ' . $e->getMessage());
                return 1;
            }
            return 0;
        }

        $webhookUrl = $this->option('url')
            ?: rtrim(config('app.url'), '/') . '/treeal/webhook';

        $this->info("Registrando webhook de Cash Out: {$webhookUrl}");

        $appUrl = rtrim(config('app.url'), '/');
        if (!str_starts_with($webhookUrl, $appUrl)) {
            $this->warn("A URL ({$webhookUrl}) não começa com APP_URL ({$appUrl}).");
            if (!$this->confirm('Continuar mesmo assim?', false)) {
                return 1;
            }
        }

        try {
            $result = $service->registerCashOutWebhook($webhookUrl);

            if ($result['success']) {
                $this->info('Webhook de Cash Out (transfer) registrado com sucesso!');
                $this->line('   URL: ' . $webhookUrl);
                if ($result['webhook_id']) {
                    $this->line('   ID:  ' . $result['webhook_id']);
                }
                $this->newLine();
                $this->info('Proximos passos:');
                $this->line('   1. Faca um saque de teste pela plataforma');
                $this->line('   2. Apos a liquidacao, o status deve mudar automaticamente para COMPLETED');
                $this->line('   3. Verifique os logs: storage/logs/laravel.log | grep treeal');
            } else {
                $this->error('Falha ao registrar webhook: ' . ($result['message'] ?? 'Erro desconhecido'));
                return 1;
            }
        } catch (\Exception $e) {
            $this->error('Excecao: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
