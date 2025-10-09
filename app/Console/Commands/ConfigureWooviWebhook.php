<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\WooviService;
use App\Models\Woovi;
use Illuminate\Support\Str;

class ConfigureWooviWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woovi:configure-webhook {--url=} {--secret=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Configura o webhook da Woovi com URL e secret de autorização';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔧 Configurando webhook da Woovi...');

        // Verificar se a Woovi está configurada
        $woovi = Woovi::first();
        if (!$woovi) {
            $this->error('❌ Woovi não está configurado. Configure primeiro na área administrativa.');
            return 1;
        }

        if (!$woovi->status) {
            $this->error('❌ Woovi está inativo. Ative primeiro na área administrativa.');
            return 1;
        }

        // Obter URL do webhook
        $webhookUrl = $this->option('url') ?: env('APP_URL') . '/api/woovi/callback';
        
        // Obter ou gerar secret
        $webhookSecret = $this->option('secret');
        if (!$webhookSecret) {
            $webhookSecret = Str::random(32);
            $this->info("🔑 Gerando webhook_secret: {$webhookSecret}");
        }

        $this->info("🌐 URL do webhook: {$webhookUrl}");
        $this->info("🔐 Secret do webhook: {$webhookSecret}");

        // Configurar webhook via API da Woovi
        $wooviService = new WooviService();
        $result = $wooviService->configureWebhook($webhookUrl, $webhookSecret);

        if ($result['success']) {
            // Salvar o webhook_secret no banco de dados
            $woovi->update(['webhook_secret' => $webhookSecret]);
            
            $this->info('✅ Webhook configurado com sucesso!');
            $this->info("📋 URL completa: {$webhookUrl}?authorization={$webhookSecret}");
            $this->info('💾 webhook_secret salvo no banco de dados');
            
            return 0;
        } else {
            $this->error('❌ Erro ao configurar webhook: ' . $result['message']);
            return 1;
        }
    }
}
