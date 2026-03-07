<?php

namespace App\Console\Commands;

use App\Services\TreealService;
use Illuminate\Console\Command;

/**
 * Registra a URL de webhook de Cashout Validation na Accounts API da Treeal/ONZ.
 *
 * Este webhook é disparado pela ONZ quando ocorre uma falha de validação
 * no cash-out (ex: saldo insuficiente, chave PIX não encontrada, dados inválidos).
 *
 * Diferente do webhook "transfer" (que notifica liquidação/status final),
 * o webhook "cashout" notifica falhas PRÉ-processamento.
 *
 * Uso:
 *   php artisan treeal:register-cashout-validation-webhook          → registra com URL padrão
 *   php artisan treeal:register-cashout-validation-webhook --list   → lista webhooks registrados
 */
class RegisterTreealCashoutValidationWebhook extends Command
{
    protected $signature = 'treeal:register-cashout-validation-webhook
                            {--url=    : URL completa do webhook (padrão: APP_URL/treeal/webhook)}
                            {--list    : Lista webhooks já registrados na Accounts API}';

    protected $description = 'Registra webhook de falha de validação de Cash Out (cashout) na Treeal/ONZ Accounts API';

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

        $this->info("Registrando webhook de Cashout Validation: {$webhookUrl}");

        $appUrl = rtrim(config('app.url'), '/');
        if (!str_starts_with($webhookUrl, $appUrl)) {
            $this->warn("A URL ({$webhookUrl}) não começa com APP_URL ({$appUrl}).");
            if (!$this->confirm('Continuar mesmo assim?', false)) {
                return 1;
            }
        }

        try {
            $result = $service->registerCashoutValidationWebhook($webhookUrl);

            if ($result['success']) {
                $this->info('Webhook de Cashout Validation registrado com sucesso!');
                $this->line('   URL: ' . $webhookUrl);
                if ($result['webhook_id']) {
                    $this->line('   ID:  ' . $result['webhook_id']);
                }
                $this->newLine();
                $this->info('O que este webhook cobre:');
                $this->line('   - Saldo insuficiente na conta ONZ');
                $this->line('   - Chave PIX nao encontrada');
                $this->line('   - Dados de pagamento invalidos');
                $this->line('   - Outras falhas de validacao pre-processamento');
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
