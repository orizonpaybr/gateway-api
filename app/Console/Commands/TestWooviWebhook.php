<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Woovi;
use Illuminate\Support\Facades\Http;

class TestWooviWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woovi:test-webhook';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa o webhook da Woovi enviando uma requisição de teste';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🧪 Testando webhook da Woovi...');

        $woovi = Woovi::first();
        if (!$woovi || !$woovi->webhook_secret) {
            $this->error('❌ Woovi não configurado ou webhook_secret não definido');
            $this->info('Execute: php artisan woovi:configure-webhook');
            return 1;
        }

        $webhookUrl = env('APP_URL') . '/api/woovi/callback?authorization=' . $woovi->webhook_secret;
        
        $this->info("🌐 URL do webhook: {$webhookUrl}");
        $this->info("🔐 Secret: {$woovi->webhook_secret}");

        // Dados de teste simulando um callback da Woovi
        $testData = [
            'event' => 'OPENPIX:CHARGE_COMPLETED',
            'charge' => [
                'identifier' => 'test_' . time(),
                'correlationID' => 'test_' . time(),
                'status' => 'COMPLETED',
                'value' => 1000, // R$ 10,00 em centavos
                'comment' => 'Teste de webhook',
                'customer' => [
                    'name' => 'Teste Webhook',
                    'taxID' => '12345678901',
                    'email' => 'teste@email.com'
                ]
            ]
        ];

        $this->info('📤 Enviando dados de teste...');
        $this->line('Dados: ' . json_encode($testData, JSON_PRETTY_PRINT));

        try {
            $response = Http::timeout(30)->post($webhookUrl, $testData);
            
            $this->info("📥 Resposta recebida:");
            $this->line("Status: " . $response->status());
            $this->line("Body: " . $response->body());
            
            if ($response->successful()) {
                $this->info('✅ Webhook funcionando corretamente!');
                return 0;
            } else {
                $this->error('❌ Webhook retornou erro: ' . $response->status());
                return 1;
            }
            
        } catch (\Exception $e) {
            $this->error('❌ Erro ao testar webhook: ' . $e->getMessage());
            return 1;
        }
    }
}
