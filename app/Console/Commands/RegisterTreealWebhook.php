<?php

namespace App\Console\Commands;

use App\Services\TreealService;
use Illuminate\Console\Command;

/**
 * Registra (ou consulta) a URL de webhook PIX na Treeal/Amplea.
 *
 * Deve ser executado UMA VEZ após qualquer migrate:fresh, troca de chave PIX
 * ou troca de URL do servidor. Sem este registro a Treeal NÃO envia
 * notificações de pagamento e os depósitos nunca creditam saldo.
 *
 * Uso:
 *   php artisan treeal:register-webhook          → registra com URL padrão (APP_URL)
 *   php artisan treeal:register-webhook --check  → apenas consulta webhook atual
 *   php artisan treeal:register-webhook --url=https://api.meusite.com/treeal/webhook
 */
class RegisterTreealWebhook extends Command
{
    protected $signature = 'treeal:register-webhook
                            {--url= : URL completa do webhook (padrão: APP_URL/treeal/webhook)}
                            {--check : Apenas consulta sem registrar}';

    protected $description = 'Registra ou consulta o webhook PIX na Treeal/Amplea (necessário para creditar depósitos)';

    public function handle(): int
    {
        $service = app(TreealService::class);

        if (!$service->isActive()) {
            $this->error('❌ Treeal não está configurado ou ativo. Verifique o .env e a tabela treeal.');
            return 1;
        }

        // ── Apenas consulta ──────────────────────────────────────────────────
        if ($this->option('check')) {
            $this->info('🔍 Consultando webhook registrado na Treeal...');
            try {
                $info = $service->getWebhookInfo();
                if ($info['success'] && $info['webhook_url']) {
                    $this->info('✅ Webhook registrado: ' . $info['webhook_url']);
                } else {
                    $this->warn('⚠️  Nenhum webhook registrado para esta chave PIX.');
                    $this->line('   Execute sem --check para registrar.');
                }
            } catch (\Exception $e) {
                $this->error('❌ Erro: ' . $e->getMessage());
                return 1;
            }
            return 0;
        }

        // ── Registrar ────────────────────────────────────────────────────────
        $webhookUrl = $this->option('url')
            ?? rtrim(config('app.url'), '/') . '/treeal/webhook';

        $this->info("🔗 Registrando webhook: {$webhookUrl}");

        // Verificar se a URL aponta para este servidor
        $appUrl = rtrim(config('app.url'), '/');
        if (!str_starts_with($webhookUrl, $appUrl)) {
            $this->warn("⚠️  A URL informada ({$webhookUrl}) não começa com APP_URL ({$appUrl}).");
            if (!$this->confirm('Continuar mesmo assim?', false)) {
                return 1;
            }
        }

        try {
            $result = $service->registerWebhook($webhookUrl);

            if ($result['success']) {
                $this->info('✅ Webhook registrado com sucesso!');
                $this->line('   URL: ' . $webhookUrl);
                $this->newLine();
                $this->info('💡 Próximos passos:');
                $this->line('   1. Faça um depósito de teste');
                $this->line('   2. Após pagar o QR Code, verifique o saldo do usuário');
                $this->line('   3. Consulte os logs: tail -f storage/logs/laravel.log | grep TREEAL');
            } else {
                $this->error('❌ Falha ao registrar webhook: ' . $result['message']);
                $this->line('   Resposta: ' . json_encode($result['data']));
                return 1;
            }
        } catch (\Exception $e) {
            $this->error('❌ Exceção: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
