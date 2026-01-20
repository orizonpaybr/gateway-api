<?php

namespace App\Console\Commands;

use App\Services\TreealService;
use App\Models\Treeal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestTreealConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'treeal:test-connection';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa conexão e autenticação com Treeal/ONZ APIs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('=== Teste de Conexão Treeal/ONZ ===');
        $this->newLine();

        // Verificar configuração
        $config = Treeal::first();
        
        if (!$config) {
            $this->error('❌ Configuração Treeal não encontrada. Execute as migrations primeiro.');
            return 1;
        }

        // Obter valores do .env ou banco (prioridade: .env)
        // Credenciais sensíveis agora vêm apenas do .env (colunas removidas do banco)
        $certPath = config('treeal.certificate_path');
        $environment = config('treeal.environment') ?? $config->environment;
        $qrcodesUrl = config('treeal.qrcodes_api_url') ?? $config->qrcodes_api_url;
        $accountsUrl = config('treeal.accounts_api_url') ?? $config->accounts_api_url;
        
        $this->info('📋 Configuração encontrada:');
        $this->line("   Ambiente: {$environment}");
        $this->line("   QR Codes API: {$qrcodesUrl}");
        $this->line("   Accounts API: {$accountsUrl}");
        $this->line("   Certificado: " . ($certPath ?: 'Não configurado'));
        $this->line("   Status: " . ($config->status ? 'Ativo' : 'Inativo'));
        $this->line("   Fonte: .env ✅ (credenciais sensíveis não estão mais no banco)");
        $this->newLine();

        // Verificar certificado
        $certificatePath = $config->getCertificateFullPath();
        if (!$certificatePath || !file_exists($certificatePath)) {
            $this->error('❌ Certificado digital não encontrado: ' . ($certificatePath ?: 'Não configurado'));
            $this->line('   Certificado esperado em: storage/app/certificates/PIX-HMG-CLIENTE.pfx');
            return 1;
        }

        $this->info('✅ Certificado encontrado: ' . $certificatePath);
        $this->newLine();

        // Testar serviço
        try {
            $service = app(TreealService::class);

            if (!$service->isConfigured()) {
                $this->error('❌ TreealService não está configurado corretamente');
                return 1;
            }

            $this->info('✅ TreealService configurado');
            $this->newLine();

            // Testar autenticação OAuth2 (Accounts API)
            // Verificar do .env primeiro, depois do banco
            $accountsClientId = config('treeal.accounts_client_id') ?? $config->client_id;
            $accountsClientSecret = config('treeal.accounts_client_secret') ?? $config->client_secret;
            
            if ($accountsClientId && $accountsClientSecret) {
                $this->info('🔐 Testando autenticação OAuth2 (Accounts API)...');
                
                try {
                    $token = $service->getAccessToken();
                    $this->info('✅ Token OAuth2 obtido com sucesso!');
                    $this->line("   Token: " . substr($token, 0, 20) . '...');
                } catch (\Exception $e) {
                    $this->error('❌ Erro ao obter token OAuth2: ' . $e->getMessage());
                    return 1;
                }
            } else {
                $this->warn('⚠️  Credenciais OAuth2 não configuradas (client_id/client_secret)');
                $this->line('   Pulando teste de autenticação OAuth2');
            }

            $this->newLine();

            // Testar geração de QR Code (se chave PIX configurada)
            // Chave PIX agora vem apenas do .env (coluna removida do banco)
            $pixKeySecondary = config('treeal.pix_key_secondary');
            
            if ($pixKeySecondary) {
                $this->info('📱 Testando geração de QR Code (Cash In)...');
                
                // Temporariamente ativar o Treeal para teste (se estiver inativo)
                $wasInactive = !$config->status;
                if ($wasInactive) {
                    $this->warn('⚠️  Treeal está inativo. Ativando temporariamente para teste...');
                    $config->status = true;
                    $config->save();
                    // Recarregar a configuração no service
                    $service->reloadConfig();
                }
                
                try {
                    $result = $service->generateQRCode(
                        10.00, // Valor de teste
                        'Teste de integração Treeal',
                        null, // txid será gerado automaticamente
                        3600 // 1 hora de expiração
                    );

                    if ($result['success']) {
                        $this->info('✅ QR Code gerado com sucesso!');
                        $this->line("   TXID: {$result['txid']}");
                        $this->line("   QR Code: " . substr($result['qr_code'] ?? 'N/A', 0, 50) . '...');
                        $this->line("   Status: {$result['status']}");
                        
                        // Restaurar status original se estava inativo
                        if ($wasInactive) {
                            $config->status = false;
                            $config->save();
                            $this->line('   Status restaurado para inativo');
                        }
                    } else {
                        $this->error('❌ Falha ao gerar QR Code');
                        // Restaurar status original se estava inativo
                        if ($wasInactive) {
                            $config->status = false;
                            $config->save();
                        }
                        return 1;
                    }
                } catch (\Exception $e) {
                    $this->error('❌ Erro ao gerar QR Code: ' . $e->getMessage());
                    $this->line('   Trace: ' . $e->getTraceAsString());
                    // Restaurar status original se estava inativo
                    if ($wasInactive) {
                        $config->status = false;
                        $config->save();
                    }
                    return 1;
                }
            } else {
                $this->warn('⚠️  Chave PIX secundária não configurada');
                $this->line('   Pulando teste de geração de QR Code');
            }

            $this->newLine();
            $this->info('✅ Todos os testes passaram!');
            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Erro geral: ' . $e->getMessage());
            $this->line('   Trace: ' . $e->getTraceAsString());
            return 1;
        }
    }
}
