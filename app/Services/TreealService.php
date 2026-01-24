<?php

namespace App\Services;

use App\Models\Treeal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Service para integração com Treeal/ONZ PIX
 * 
 * Documentação:
 * - QR Codes API (Cash In): https://treeal-pix.readme.io/reference/about-us
 * - Accounts API (Cash Out): https://developers.onz.software/docs/intro
 * 
 * Autenticação:
 * - QR Codes API: Certificado digital .PFX
 * - Accounts API: OAuth2 (client_credentials) + Certificado digital
 */
class TreealService
{
    private Treeal $config;
    private ?string $accessToken = null;
    
    // Credenciais sensíveis (vêm apenas do .env, não do banco)
    private ?string $certificatePath = null;
    private ?string $certificatePassword = null;
    private ?string $accountsClientId = null;
    private ?string $accountsClientSecret = null;
    private ?string $qrcodesClientId = null;
    private ?string $qrcodesClientSecret = null;
    private ?string $pixKeySecondary = null;
    
    // Cache TTL para tokens OAuth (normalmente expiram em 1 hora)
    private const TOKEN_CACHE_TTL = 3600;
    
    // Configurações de retry
    private int $maxRetries;
    private int $retryDelayMs;
    private int $timeout;
    private int $connectTimeout;
    
    public function __construct()
    {
        // Carregar configuração do banco (URLs, taxas, status)
        $this->config = Treeal::first() ?? new Treeal();
        
        // Carregar credenciais sensíveis apenas do .env (não existem mais no banco)
        $this->certificatePath = config('treeal.certificate_path');
        $this->certificatePassword = config('treeal.certificate_password');
        $this->accountsClientId = config('treeal.accounts_client_id');
        $this->accountsClientSecret = config('treeal.accounts_client_secret');
        $this->qrcodesClientId = config('treeal.qrcodes_client_id');
        $this->qrcodesClientSecret = config('treeal.qrcodes_client_secret');
        $this->pixKeySecondary = config('treeal.pix_key_secondary');
        
        // Configurações de retry
        $this->maxRetries = config('treeal.max_retries', 3);
        $this->retryDelayMs = config('treeal.retry_delay_ms', 1000);
        $this->timeout = config('treeal.timeout', 30);
        $this->connectTimeout = config('treeal.connect_timeout', 10);
        
        // URLs e ambiente podem vir do .env ou banco (prioridade: .env)
        $this->config->environment = config('treeal.environment') 
            ?? $this->config->environment;
            
        $this->config->qrcodes_api_url = config('treeal.qrcodes_api_url') 
            ?? $this->config->qrcodes_api_url;
            
        $this->config->accounts_api_url = config('treeal.accounts_api_url') 
            ?? $this->config->accounts_api_url;
    }

    /**
     * Recarrega a configuração do banco de dados
     * Útil quando a configuração é alterada durante execução
     */
    public function reloadConfig(): void
    {
        $this->config = Treeal::first() ?? new Treeal();
        
        // Recarregar credenciais sensíveis do .env (sempre do .env, não do banco)
        $this->certificatePath = config('treeal.certificate_path');
        $this->certificatePassword = config('treeal.certificate_password');
        $this->accountsClientId = config('treeal.accounts_client_id');
        $this->accountsClientSecret = config('treeal.accounts_client_secret');
        $this->qrcodesClientId = config('treeal.qrcodes_client_id');
        $this->qrcodesClientSecret = config('treeal.qrcodes_client_secret');
        $this->pixKeySecondary = config('treeal.pix_key_secondary');
        
        // URLs e ambiente podem vir do .env ou banco (prioridade: .env)
        $this->config->environment = config('treeal.environment') 
            ?? $this->config->environment;
            
        $this->config->qrcodes_api_url = config('treeal.qrcodes_api_url') 
            ?? $this->config->qrcodes_api_url;
            
        $this->config->accounts_api_url = config('treeal.accounts_api_url') 
            ?? $this->config->accounts_api_url;
    }

    /**
     * Verifica se o serviço está configurado
     */
    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * Verifica se está ativo
     */
    public function isActive(): bool
    {
        return $this->config->isActive();
    }

    /**
     * Converte certificado .PFX para formato PEM (certificado + chave)
     * 
     * @return array ['cert' => caminho do cert PEM, 'key' => caminho da key PEM]
     */
    private function convertPfxToPem(): array
    {
        $pfxPath = $this->config->getCertificateFullPath();
        $password = $this->certificatePassword;
        
        if (!$pfxPath || !file_exists($pfxPath)) {
            throw new \Exception("Certificado digital não encontrado: {$pfxPath}");
        }

        // Criar diretório temporário para certificados PEM
        $pemDir = storage_path('app/certificates/pem');
        if (!is_dir($pemDir)) {
            mkdir($pemDir, 0755, true);
        }

        // Nome base do arquivo (sem extensão)
        $baseName = pathinfo($pfxPath, PATHINFO_FILENAME);
        $certPemPath = $pemDir . '/' . $baseName . '_cert.pem';
        $keyPemPath = $pemDir . '/' . $baseName . '_key.pem';
        $pemPath = $pemDir . '/' . $baseName . '.pem';

        // Verificar se já existe (cache)
        if (file_exists($certPemPath) && file_exists($keyPemPath)) {
            // Verificar se o arquivo .PFX não foi modificado
            if (filemtime($pfxPath) <= filemtime($certPemPath)) {
                return [
                    'cert' => $certPemPath,
                    'key' => $keyPemPath,
                ];
            }
        }

        // Converter .PFX para PEM usando OpenSSL
        // Primeiro, extrair certificado e chave separadamente
        $command = sprintf(
            'openssl pkcs12 -in %s -nodes -passin pass:%s -out %s 2>&1',
            escapeshellarg($pfxPath),
            escapeshellarg($password),
            escapeshellarg($pemPath)
        );

        Log::debug('TreealService::convertPfxToPem - Convertendo certificado', [
            'pfx_path' => $pfxPath,
            'pem_path' => $pemPath,
        ]);

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $errorOutput = implode("\n", $output);
            Log::error('TreealService::convertPfxToPem - Erro na conversão', [
                'return_code' => $returnCode,
                'output' => $errorOutput,
            ]);
            throw new \Exception("Erro ao converter certificado .PFX para PEM: " . $errorOutput);
        }

        if (!file_exists($pemPath)) {
            throw new \Exception("Arquivo PEM não foi criado após conversão");
        }

        // Ler o arquivo PEM e separar certificado e chave
        $pemContent = file_get_contents($pemPath);
        
        if (empty($pemContent)) {
            throw new \Exception("Arquivo PEM está vazio após conversão");
        }

        // Extrair certificado (pode haver múltiplos certificados, pegar o primeiro)
        if (preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pemContent, $certMatches)) {
            file_put_contents($certPemPath, $certMatches[0] . "\n");
            chmod($certPemPath, 0600);
            Log::debug('TreealService::convertPfxToPem - Certificado extraído', [
                'cert_path' => $certPemPath,
            ]);
        } else {
            throw new \Exception("Não foi possível extrair o certificado do arquivo .PFX. Conteúdo PEM: " . substr($pemContent, 0, 200));
        }

        // Extrair chave privada (pode ser RSA PRIVATE KEY ou PRIVATE KEY)
        $keyPatterns = [
            '/-----BEGIN RSA PRIVATE KEY-----.*?-----END RSA PRIVATE KEY-----/s',
            '/-----BEGIN PRIVATE KEY-----.*?-----END PRIVATE KEY-----/s',
            '/-----BEGIN ENCRYPTED PRIVATE KEY-----.*?-----END ENCRYPTED PRIVATE KEY-----/s',
        ];

        $keyExtracted = false;
        foreach ($keyPatterns as $pattern) {
            if (preg_match($pattern, $pemContent, $keyMatches)) {
                file_put_contents($keyPemPath, $keyMatches[0] . "\n");
                chmod($keyPemPath, 0600);
                $keyExtracted = true;
                Log::debug('TreealService::convertPfxToPem - Chave privada extraída', [
                    'key_path' => $keyPemPath,
                ]);
                break;
            }
        }

        if (!$keyExtracted) {
            throw new \Exception("Não foi possível extrair a chave privada do arquivo .PFX. Conteúdo PEM: " . substr($pemContent, 0, 200));
        }

        // Remover arquivo temporário
        if (file_exists($pemPath)) {
            unlink($pemPath);
        }

        return [
            'cert' => $certPemPath,
            'key' => $keyPemPath,
        ];
    }

    /**
     * Obtém token OAuth2 para QR Codes API
     * 
     * A QR Codes API também requer OAuth2 além do certificado digital
     */
    private function getQRCodesAccessToken(): string
    {
        // Verificar cache primeiro
        $cacheKey = "treeal:oauth_token_qrcodes:{$this->config->id}";
        $cachedToken = Cache::get($cacheKey);
        
        if ($cachedToken) {
            Log::debug('TreealService::getQRCodesAccessToken - Token obtido do cache');
            return $cachedToken;
        }

        $certificatePath = $this->config->getCertificateFullPath();
        $certificatePassword = $this->certificatePassword;
        
        // Usar credenciais específicas da QR Codes API se disponíveis, senão usar as genéricas
        $clientId = $this->qrcodesClientId ?? $this->accountsClientId;
        $clientSecret = $this->qrcodesClientSecret ?? $this->accountsClientSecret;

        if (!$certificatePath || !file_exists($certificatePath)) {
            throw new \Exception("Certificado digital não encontrado: {$certificatePath}");
        }

        if (!$certificatePassword) {
            throw new \Exception("Senha do certificado não configurada");
        }

        if (!$clientId || !$clientSecret) {
            throw new \Exception("Credenciais OAuth2 para QR Codes API não configuradas (qrcodes_client_id/qrcodes_client_secret ou client_id/client_secret)");
        }

        try {
            Log::info('TreealService::getQRCodesAccessToken - Obtendo novo token OAuth2 para QR Codes API', [
                'qrcodes_api_url' => $this->config->qrcodes_api_url,
                'client_id' => substr($clientId, 0, 15) . '...', // Log parcial por segurança
                'client_id_length' => strlen($clientId),
                'client_secret_length' => strlen($clientSecret),
                'has_qrcodes_credentials' => !empty($this->qrcodesClientId),
            ]);

            // Converter .PFX para PEM
            $pemFiles = $this->convertPfxToPem();

            // Em ambiente sandbox, desabilitar verificação SSL
            $verifySSL = $this->config->environment !== 'sandbox';

            // Preparar payload - garantir que não há espaços ou caracteres especiais
            // Tentar primeiro sem scope, já que pode ser opcional
            $payload = [
                'grant_type' => 'client_credentials',
                'client_id' => trim($clientId),
                'client_secret' => trim($clientSecret),
            ];

            Log::debug('TreealService::getQRCodesAccessToken - Payload da requisição (sem scope)', [
                'grant_type' => $payload['grant_type'],
                'client_id' => substr($payload['client_id'], 0, 15) . '...',
                'client_id_full' => $payload['client_id'], // Log completo para debug
                'client_secret_length' => strlen($payload['client_secret']),
            ]);

            $response = Http::withOptions([
                'verify' => $verifySSL,
                'cert' => $pemFiles['cert'],
                'ssl_key' => [$pemFiles['key'], $certificatePassword],
            ])->asForm()->post($this->config->qrcodes_api_url . '/oauth/token', $payload);
            
            // Se falhar sem scope, tentar com scope
            if (!$response->successful() && $response->status() === 401) {
                Log::info('TreealService::getQRCodesAccessToken - Tentando novamente com scope');
                $payload['scope'] = 'cob.write cob.read';
                
                $response = Http::withOptions([
                    'verify' => $verifySSL,
                    'cert' => $pemFiles['cert'],
                    'ssl_key' => [$pemFiles['key'], $certificatePassword],
                ])->asForm()->post($this->config->qrcodes_api_url . '/oauth/token', $payload);
            }

            if (!$response->successful()) {
                $errorBody = $response->body();
                Log::error('TreealService::getQRCodesAccessToken - Erro ao obter token', [
                    'status' => $response->status(),
                    'response' => $errorBody,
                ]);
                
                $errorData = json_decode($errorBody, true);
                $errorMessage = 'Erro ao obter token OAuth2';
                
                if (is_array($errorData)) {
                    $errorMessage = $errorData['detail'] 
                        ?? $errorData['title'] 
                        ?? $errorData['message'] 
                        ?? $errorData['error'] 
                        ?? json_encode($errorData);
                } elseif (!empty($errorBody)) {
                    $errorMessage = $errorBody;
                }
                
                throw new \Exception("Erro ao obter token OAuth2 QR Codes ({$response->status()}): {$errorMessage}");
            }

            $data = $response->json();
            $token = $data['access_token'] ?? null;

            if (!$token) {
                throw new \Exception("Token não encontrado na resposta OAuth2. Resposta: " . json_encode($data));
            }

            // Cachear token
            $expiresIn = $data['expires_in'] ?? self::TOKEN_CACHE_TTL;
            Cache::put($cacheKey, $token, now()->addSeconds($expiresIn - 60));

            Log::info('TreealService::getQRCodesAccessToken - Token obtido com sucesso', [
                'expires_in' => $expiresIn,
            ]);

            return $token;

        } catch (\Exception $e) {
            Log::error('TreealService::getQRCodesAccessToken - Exceção', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Obtém cliente HTTP configurado com certificado digital + OAuth2 para QR Codes API
     * 
     * A QR Codes API usa autenticação via certificado digital .PFX + OAuth2
     * Converte .PFX para PEM para compatibilidade com Guzzle
     */
    private function getQRCodesHttpClient()
    {
        $certificatePath = $this->config->getCertificateFullPath();
        $certificatePassword = $this->certificatePassword;

        if (!$certificatePath || !file_exists($certificatePath)) {
            throw new \Exception("Certificado digital não encontrado: {$certificatePath}");
        }

        if (!$certificatePassword) {
            throw new \Exception("Senha do certificado não configurada");
        }

        // Converter .PFX para PEM
        $pemFiles = $this->convertPfxToPem();

        // A QR Codes API requer OAuth2, mas pode precisar de credenciais diferentes
        // Ou pode funcionar apenas com certificado digital
        // Tentar obter token da QR Codes API primeiro
        $accessToken = null;
        $useOAuth2 = true;
        
        try {
            $accessToken = $this->getQRCodesAccessToken();
            Log::debug('TreealService::getQRCodesHttpClient - Usando token específico da QR Codes API');
        } catch (\Exception $e) {
            Log::warning('TreealService::getQRCodesHttpClient - Falha ao obter token da QR Codes API', [
                'error' => $e->getMessage(),
            ]);
            
            // Se o erro for "Credenciais inválidas", pode ser que a QR Codes API
            // não use OAuth2 ou use credenciais diferentes
            // Tentar sem OAuth2 (apenas certificado)
            if (str_contains($e->getMessage(), 'Credenciais inválidas') || str_contains($e->getMessage(), '401')) {
                Log::info('TreealService::getQRCodesHttpClient - Tentando apenas com certificado digital (sem OAuth2)');
                $useOAuth2 = false;
            } else {
                // Outro erro, tentar token da Accounts API como fallback
                try {
                    $accessToken = $this->getAccessToken();
                    Log::debug('TreealService::getQRCodesHttpClient - Usando token da Accounts API como fallback');
                } catch (\Exception $e2) {
                    Log::warning('TreealService::getQRCodesHttpClient - Falha ao obter token da Accounts API, tentando apenas com certificado', [
                        'error_accounts' => $e2->getMessage(),
                    ]);
                    $useOAuth2 = false;
                }
            }
        }

        // Em ambiente sandbox, desabilitar verificação SSL (certificados auto-assinados)
        // Em produção, manter verificação SSL ativa
        $verifySSL = $this->config->environment !== 'sandbox';

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        // Adicionar token apenas se disponível e necessário
        if ($useOAuth2 && $accessToken) {
            $headers['Authorization'] = 'Bearer ' . $accessToken;
            Log::debug('TreealService::getQRCodesHttpClient - Headers incluem Authorization Bearer');
        } else {
            Log::debug('TreealService::getQRCodesHttpClient - Usando apenas certificado digital (sem OAuth2)');
        }

        return Http::withOptions([
            'verify' => $verifySSL, // Desabilitar verificação SSL em sandbox
            'cert' => $pemFiles['cert'],
            'ssl_key' => [$pemFiles['key'], $certificatePassword],
        ])->withHeaders($headers);
    }

    /**
     * Obtém token OAuth2 para Accounts API
     * 
     * Usa certificado digital + client_credentials
     * 
     * @return string Access token
     * @throws \Exception Se falhar autenticação
     */
    public function getAccessToken(): string
    {
        // Verificar cache primeiro
        $cacheKey = "treeal:oauth_token:{$this->config->id}";
        $cachedToken = Cache::get($cacheKey);
        
        if ($cachedToken) {
            Log::debug('TreealService::getAccessToken - Token obtido do cache');
            return $cachedToken;
        }

        $certificatePath = $this->config->getCertificateFullPath();
        $certificatePassword = $this->certificatePassword;
        $clientId = $this->accountsClientId;
        $clientSecret = $this->accountsClientSecret;

        if (!$certificatePath || !file_exists($certificatePath)) {
            throw new \Exception("Certificado digital não encontrado: {$certificatePath}");
        }

        if (!$certificatePassword) {
            throw new \Exception("Senha do certificado não configurada");
        }

        if (!$clientId || !$clientSecret) {
            throw new \Exception("Credenciais OAuth2 não configuradas (client_id/client_secret)");
        }

        try {
            Log::info('TreealService::getAccessToken - Obtendo novo token OAuth2', [
                'accounts_api_url' => $this->config->accounts_api_url,
            ]);

            // Converter .PFX para PEM para compatibilidade com Guzzle
            $pemFiles = $this->convertPfxToPem();

            // Em ambiente sandbox, desabilitar verificação SSL (certificados auto-assinados)
            // Em produção, manter verificação SSL ativa
            $verifySSL = $this->config->environment !== 'sandbox';

            $response = Http::withOptions([
                'verify' => $verifySSL, // Desabilitar verificação SSL em sandbox
                'cert' => $pemFiles['cert'],
                'ssl_key' => [$pemFiles['key'], $certificatePassword],
            ])->asForm()->post($this->config->accounts_api_url . '/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => 'pix.write pix.read transactions.read account.read',
            ]);

            if (!$response->successful()) {
                $errorBody = $response->body();
                Log::error('TreealService::getAccessToken - Erro ao obter token', [
                    'status' => $response->status(),
                    'response' => $errorBody,
                ]);
                
                // Tentar parsear erro RFC 7807
                $errorData = json_decode($errorBody, true);
                $errorMessage = $errorData['detail'] ?? $errorData['title'] ?? 'Erro ao obter token OAuth2';
                
                throw new \Exception("Erro ao obter token OAuth2 ({$response->status()}): {$errorMessage}");
            }

            $data = $response->json();
            $token = $data['access_token'] ?? null;

            if (!$token) {
                throw new \Exception("Token não encontrado na resposta OAuth2. Resposta: " . json_encode($data));
            }

            // Cachear token
            $expiresIn = $data['expires_in'] ?? self::TOKEN_CACHE_TTL;
            Cache::put($cacheKey, $token, now()->addSeconds($expiresIn - 60)); // Cache com 1 minuto de margem

            Log::info('TreealService::getAccessToken - Token obtido com sucesso', [
                'expires_in' => $expiresIn,
                'token_type' => $data['token_type'] ?? 'Bearer',
            ]);

            return $token;

        } catch (\Exception $e) {
            Log::error('TreealService::getAccessToken - Exceção', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Obtém cliente HTTP configurado com certificado + OAuth2 para Accounts API
     */
    private function getAccountsHttpClient()
    {
        $certificatePath = $this->config->getCertificateFullPath();
        $certificatePassword = $this->certificatePassword;
        $accessToken = $this->getAccessToken();

        // Converter .PFX para PEM para compatibilidade com Guzzle
        $pemFiles = $this->convertPfxToPem();

        // Em ambiente sandbox, desabilitar verificação SSL (certificados auto-assinados)
        // Em produção, manter verificação SSL ativa
        $verifySSL = $this->config->environment !== 'sandbox';

        return Http::withOptions([
            'verify' => $verifySSL, // Desabilitar verificação SSL em sandbox
            'cert' => $pemFiles['cert'],
            'ssl_key' => [$pemFiles['key'], $certificatePassword],
        ])->withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);
    }

    /**
     * Gera QR Code para depósito (Cash In)
     * 
     * Endpoint: POST /cob/{txid} (QR Codes API)
     * Documentação: https://treeal-pix.readme.io/reference/post-cob-txid
     * 
     * @param float $amount Valor do depósito
     * @param string $description Descrição do pagamento
     * @param string|null $txid Transaction ID (opcional, será gerado se não fornecido)
     * @param int $expirationSeconds Tempo de expiração em segundos (padrão: 3600 = 1 hora)
     * @return array Resposta da API com QR Code
     */
    public function generateQRCode(float $amount, string $description, ?string $txid = null, int $expirationSeconds = 3600): array
    {
        if (!$this->isActive()) {
            throw new \Exception("Treeal não está configurado ou ativo");
        }

        if (!$this->pixKeySecondary) {
            throw new \Exception("Chave PIX secundária não configurada");
        }

        // Gerar txid se não fornecido (formato: 26-35 caracteres alfanuméricos conforme padrão PIX)
        if (!$txid) {
            // Gerar 32 caracteres alfanuméricos (padrão mais comum)
            $txid = strtoupper(bin2hex(random_bytes(16)));
        }

        // Validar formato do txid (26-35 caracteres alfanuméricos)
        if (!preg_match('/^[a-zA-Z0-9]{26,35}$/', $txid)) {
            throw new \Exception("txid inválido. Deve ter entre 26 e 35 caracteres alfanuméricos");
        }

        $payload = [
            'calendario' => [
                'expiracao' => $expirationSeconds,
            ],
            'valor' => [
                'original' => number_format($amount, 2, '.', ''),
            ],
            'chave' => $this->pixKeySecondary,
            'solicitacaoPagador' => substr($description, 0, 140), // Máximo 140 caracteres
        ];

        try {
            Log::info('TreealService::generateQRCode - Gerando QR Code', [
                'amount' => $amount,
                'txid' => $txid,
                'pix_key' => $this->pixKeySecondary,
            ]);

            // Endpoint: PUT /cob/{txid} (cria ou atualiza cobrança imediata)
            $response = $this->getQRCodesHttpClient()
                ->put($this->config->qrcodes_api_url . '/cob/' . $txid, $payload);

            if (!$response->successful()) {
                $errorBody = $response->body();
                $errorHeaders = $response->headers();
                
                Log::error('TreealService::generateQRCode - Erro na API', [
                    'status' => $response->status(),
                    'response' => $errorBody,
                    'headers' => $errorHeaders,
                    'txid' => $txid,
                    'url' => $this->config->qrcodes_api_url . '/cob/' . $txid,
                ]);
                
                // Tentar parsear erro RFC 7807 ou JSON genérico
                $errorData = json_decode($errorBody, true);
                $errorMessage = 'Erro ao gerar QR Code';
                
                if (is_array($errorData)) {
                    $errorMessage = $errorData['detail'] 
                        ?? $errorData['title'] 
                        ?? $errorData['message'] 
                        ?? $errorData['error'] 
                        ?? json_encode($errorData);
                } elseif (!empty($errorBody)) {
                    $errorMessage = $errorBody;
                }
                
                throw new \Exception("Erro ao gerar QR Code ({$response->status()}): {$errorMessage}");
            }

            $data = $response->json();

            Log::info('TreealService::generateQRCode - QR Code gerado com sucesso', [
                'txid' => $txid,
                'status' => $data['status'] ?? 'UNKNOWN',
            ]);

            // Obter QR Code da location se necessário
            $qrCode = $data['pixCopiaECola'] ?? null;
            $location = $data['loc']['location'] ?? null;

            // Se não tem pixCopiaECola direto, buscar da location
            if (!$qrCode && $location) {
                try {
                    $locationResponse = $this->getQRCodesHttpClient()
                        ->get($location);
                    
                    if ($locationResponse->successful()) {
                        $locationData = $locationResponse->json();
                        $qrCode = $locationData['pixCopiaECola'] ?? $qrCode;
                    }
                } catch (\Exception $e) {
                    Log::warning('TreealService::generateQRCode - Erro ao buscar location', [
                        'location' => $location,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return [
                'success' => true,
                'txid' => $txid,
                'qr_code' => $qrCode,
                'qr_code_image_url' => null, // Será gerado no front-end
                'location' => $location,
                'status' => $data['status'] ?? 'ATIVA',
                'expires_at' => isset($data['calendario']['expiracao']) 
                    ? now()->addSeconds($data['calendario']['expiracao'])->toIso8601String()
                    : now()->addSeconds($expirationSeconds)->toIso8601String(),
                'data' => $data,
            ];

        } catch (\Exception $e) {
            Log::error('TreealService::generateQRCode - Exceção', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'txid' => $txid,
            ]);
            throw $e;
        }
    }

    /**
     * Consulta status de uma cobrança (Cash In)
     * 
     * Endpoint: GET /cob/{txid}
     */
    public function getCobStatus(string $txid): array
    {
        if (!$this->isActive()) {
            throw new \Exception("Treeal não está configurado ou ativo");
        }

        try {
            $response = $this->getQRCodesHttpClient()
                ->get($this->config->qrcodes_api_url . '/cob/' . $txid);

            if ($response->status() === 404) {
                return [
                    'success' => false,
                    'status' => 'NOT_FOUND',
                ];
            }

            if (!$response->successful()) {
                throw new \Exception("Erro ao consultar cobrança: " . $response->body());
            }

            $data = $response->json();
            $status = $data['status'] ?? 'UNKNOWN';

            return [
                'success' => true,
                'status' => $status,
                'data' => $data,
            ];

        } catch (\Exception $e) {
            Log::error('TreealService::getCobStatus - Exceção', [
                'error' => $e->getMessage(),
                'txid' => $txid,
            ]);
            throw $e;
        }
    }

    /**
     * Realiza saque PIX (Cash Out) usando chave PIX
     * 
     * Endpoint: POST /pix/payments/dict (Accounts API)
     * 
     * @param float $amount Valor do saque
     * @param string $pixKey Chave PIX (CPF, CNPJ, Email, Telefone ou EVP)
     * @param string $description Descrição do pagamento
     * @param string|null $idempotencyKey Chave de idempotência (opcional)
     * @return array Resposta da API
     */
    public function createWithdrawalByPixKey(
        float $amount,
        string $pixKey,
        string $description,
        ?string $idempotencyKey = null,
        ?string $pixKeyType = null
    ): array {
        if (!$this->isActive()) {
            throw new \Exception("Treeal não está configurado ou ativo");
        }

        // Gerar idempotency key se não fornecido
        if (!$idempotencyKey) {
            $idempotencyKey = str()->uuid()->toString();
        }

        // Normalizar chave PIX baseado no tipo
        $normalizedPixKey = $this->normalizePixKey($pixKey, $pixKeyType);

        // Formato conforme documentação: PixDictData
        // amount deve ser número (double), não string formatada
        $payload = [
            'pixKey' => $normalizedPixKey,
            'payment' => [
                'currency' => 'BRL',
                'amount' => (float) $amount, // Número, não string
            ],
            'description' => substr($description, 0, 140), // Máximo 140 caracteres
            'priority' => 'NORM', // NORM ou HIGH (HIGH requer creditorDocument)
        ];

        try {
            Log::info('TreealService::createWithdrawalByPixKey - Criando saque', [
                'amount' => $amount,
                'pix_key' => $pixKey,
                'pix_key_normalized' => $normalizedPixKey,
                'pix_key_type' => $pixKeyType,
                'idempotency_key' => $idempotencyKey,
            ]);

            $response = $this->getAccountsHttpClient()
                ->withHeader('x-idempotency-key', $idempotencyKey)
                ->post($this->config->accounts_api_url . '/pix/payments/dict', $payload);

            if (!$response->successful()) {
                $errorBody = $response->body();
                Log::error('TreealService::createWithdrawalByPixKey - Erro na API', [
                    'status' => $response->status(),
                    'response' => $errorBody,
                    'payload' => $payload,
                    'pix_key_original' => $pixKey,
                    'pix_key_normalized' => $normalizedPixKey,
                    'pix_key_type' => $pixKeyType,
                    'idempotency_key' => $idempotencyKey,
                ]);
                
                // Tentar parsear erro RFC 7807
                $errorData = json_decode($errorBody, true);
                $errorMessage = $errorData['detail'] ?? $errorData['title'] ?? $errorData['message'] ?? 'Erro ao criar saque';
                
                // Se houver erros específicos, incluí-los
                if (isset($errorData['errors']) && is_array($errorData['errors'])) {
                    $errorMessage .= ' - ' . json_encode($errorData['errors']);
                }
                
                throw new \Exception("Erro ao criar saque ({$response->status()}): {$errorMessage}");
            }

            $data = $response->json();

            Log::info('TreealService::createWithdrawalByPixKey - Saque criado com sucesso', [
                'idempotency_key' => $idempotencyKey,
                'response_data' => $data,
            ]);

            // Extrair transaction_id da resposta
            // A API pode retornar em diferentes campos: id, transactionId, endToEndId
            $transactionId = $data['id'] 
                ?? $data['transactionId'] 
                ?? $data['endToEndId'] 
                ?? $data['paymentId']
                ?? null;

            return [
                'success' => true,
                'idempotency_key' => $idempotencyKey,
                'transaction_id' => $transactionId,
                'end_to_end_id' => $data['endToEndId'] ?? $transactionId,
                'status' => $data['status'] ?? 'PROCESSING',
                'data' => $data,
            ];

        } catch (\Exception $e) {
            Log::error('TreealService::createWithdrawalByPixKey - Exceção', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Consulta status de um pagamento (Cash Out)
     * 
     * Endpoint: GET /pix/payments/{endToEndId}
     */
    public function getPaymentStatus(string $endToEndId): array
    {
        if (!$this->isActive()) {
            throw new \Exception("Treeal não está configurado ou ativo");
        }

        try {
            $response = $this->getAccountsHttpClient()
                ->get($this->config->accounts_api_url . '/pix/payments/' . $endToEndId);

            if ($response->status() === 404) {
                return [
                    'success' => false,
                    'status' => 'NOT_FOUND',
                ];
            }

            if (!$response->successful()) {
                $errorBody = $response->body();
                Log::error('TreealService::getPaymentStatus - Erro na API', [
                    'status' => $response->status(),
                    'response' => $errorBody,
                    'end_to_end_id' => $endToEndId,
                ]);
                
                // Tentar parsear erro RFC 7807
                $errorData = json_decode($errorBody, true);
                $errorMessage = $errorData['detail'] ?? $errorData['title'] ?? 'Erro ao consultar pagamento';
                
                throw new \Exception("Erro ao consultar pagamento ({$response->status()}): {$errorMessage}");
            }

            $data = $response->json();
            
            // Resposta pode ser PixData ou PixQueuedData
            $status = $data['status'] ?? ($data['data']['status'] ?? 'UNKNOWN');

            return [
                'success' => true,
                'status' => $status,
                'end_to_end_id' => $data['endToEndId'] ?? ($data['data']['endToEndId'] ?? null),
                'data' => $data,
            ];

        } catch (\Exception $e) {
            Log::error('TreealService::getPaymentStatus - Exceção', [
                'error' => $e->getMessage(),
                'end_to_end_id' => $endToEndId,
            ]);
            throw $e;
        }
    }

    /**
     * Normaliza chave PIX baseado no tipo
     * 
     * Remove caracteres especiais de CPF, CNPJ e telefone
     * Mantém email e EVP como estão
     */
    private function normalizePixKey(string $pixKey, ?string $pixKeyType = null): string
    {
        if (!$pixKeyType) {
            // Tentar detectar o tipo automaticamente
            if (filter_var($pixKey, FILTER_VALIDATE_EMAIL)) {
                return $pixKey; // Email já está no formato correto
            }
            if (preg_match('/^[0-9]{11}$/', $pixKey)) {
                return $pixKey; // CPF já está normalizado
            }
            if (preg_match('/^[0-9]{14}$/', $pixKey)) {
                return $pixKey; // CNPJ já está normalizado
            }
            if (preg_match('/^\+55[0-9]{10,11}$/', $pixKey)) {
                return $pixKey; // Telefone já está normalizado
            }
            // Se não conseguir detectar, retorna como está
            return $pixKey;
        }

        $type = strtolower($pixKeyType);

        switch ($type) {
            case 'cpf':
            case 'cnpj':
                // Remover todos os caracteres não numéricos
                return preg_replace('/[^0-9]/', '', $pixKey);
            
            case 'telefone':
            case 'phone':
                // Remover todos os caracteres não numéricos (exceto +)
                $normalized = preg_replace('/[^0-9+]/', '', $pixKey);
                
                // Se já começar com +, manter
                if (str_starts_with($normalized, '+')) {
                    // Verificar se já tem código do país
                    if (str_starts_with($normalized, '+55')) {
                        return $normalized; // Já está no formato correto: +5511999999999
                    }
                    // Se tem + mas não tem 55, adicionar
                    $normalized = '+55' . substr($normalized, 1);
                    return $normalized;
                }
                
                // Se não tem +, adicionar
                // Se começar com 55, adicionar +
                if (str_starts_with($normalized, '55')) {
                    return '+' . $normalized; // +5511999999999
                }
                
                // Se começar com 0, remover
                if (str_starts_with($normalized, '0')) {
                    $normalized = substr($normalized, 1);
                }
                
                // Verificar tamanho válido (10 ou 11 dígitos = DDD + número)
                if (strlen($normalized) >= 10 && strlen($normalized) <= 11) {
                    // Adicionar código do país +55 e o +
                    return '+55' . $normalized; // +5511999999999
                }
                
                // Se não está no formato esperado, tentar adicionar +55 mesmo assim
                Log::warning('TreealService::normalizePixKey - Telefone com formato inesperado, tentando normalizar', [
                    'original' => $pixKey,
                    'normalized' => $normalized,
                    'length' => strlen($normalized)
                ]);
                
                // Tentar adicionar +55 se não tiver
                if (!str_starts_with($normalized, '+')) {
                    return '+55' . $normalized;
                }
                
                return $normalized;
            
            case 'email':
                // Email já está no formato correto
                return strtolower(trim($pixKey));
            
            case 'evp':
            case 'aleatoria':
                // EVP (chave aleatória) já está no formato correto (UUID)
                return strtolower(trim($pixKey));
            
            default:
                // Se tipo desconhecido, retornar como está
                Log::warning('TreealService::normalizePixKey - Tipo de chave PIX desconhecido', [
                    'type' => $pixKeyType,
                    'key' => $pixKey
                ]);
                return $pixKey;
        }
    }

    /**
     * Limpa cache de token OAuth2 (útil para testes ou quando token expira)
     */
    public function clearTokenCache(): void
    {
        $cacheKey = "treeal:oauth_token:{$this->config->id}";
        Cache::forget($cacheKey);
    }
    
    /**
     * Executa uma chamada HTTP com retry automático
     * 
     * @param callable $httpCall Função que faz a chamada HTTP e retorna Response
     * @param string $operation Nome da operação para logs
     * @param array $context Contexto adicional para logs
     * @return \Illuminate\Http\Client\Response
     * @throws \Exception Se todas as tentativas falharem
     */
    private function executeWithRetry(callable $httpCall, string $operation, array $context = []): \Illuminate\Http\Client\Response
    {
        $lastException = null;
        $lastResponse = null;
        
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                Log::debug("TreealService::{$operation} - Tentativa {$attempt}/{$this->maxRetries}", $context);
                
                $response = $httpCall();
                
                // Se a resposta foi bem sucedida, retornar
                if ($response->successful()) {
                    if ($attempt > 1) {
                        Log::info("TreealService::{$operation} - Sucesso na tentativa {$attempt}", $context);
                    }
                    return $response;
                }
                
                // Se for erro de cliente (4xx), não fazer retry (exceto 429 - rate limit)
                if ($response->status() >= 400 && $response->status() < 500 && $response->status() !== 429) {
                    Log::warning("TreealService::{$operation} - Erro de cliente, sem retry", [
                        'status' => $response->status(),
                        'attempt' => $attempt,
                        ...$context,
                    ]);
                    return $response;
                }
                
                $lastResponse = $response;
                
                Log::warning("TreealService::{$operation} - Erro na tentativa {$attempt}", [
                    'status' => $response->status(),
                    'body_preview' => substr($response->body(), 0, 200),
                    ...$context,
                ]);
                
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $lastException = $e;
                Log::warning("TreealService::{$operation} - Erro de conexão na tentativa {$attempt}", [
                    'error' => $e->getMessage(),
                    ...$context,
                ]);
            } catch (\Exception $e) {
                $lastException = $e;
                Log::warning("TreealService::{$operation} - Exceção na tentativa {$attempt}", [
                    'error' => $e->getMessage(),
                    'type' => get_class($e),
                    ...$context,
                ]);
            }
            
            // Se não é a última tentativa, aguardar com backoff exponencial
            if ($attempt < $this->maxRetries) {
                $delay = $this->retryDelayMs * pow(2, $attempt - 1);
                $jitter = rand(0, (int)($delay * 0.1)); // 10% de jitter
                $totalDelay = $delay + $jitter;
                
                Log::debug("TreealService::{$operation} - Aguardando {$totalDelay}ms antes da próxima tentativa");
                usleep($totalDelay * 1000); // converter para microsegundos
            }
        }
        
        // Todas as tentativas falharam
        Log::error("TreealService::{$operation} - Todas as tentativas falharam", [
            'max_retries' => $this->maxRetries,
            'last_error' => $lastException ? $lastException->getMessage() : null,
            'last_status' => $lastResponse ? $lastResponse->status() : null,
            ...$context,
        ]);
        
        // Se temos uma resposta (mesmo com erro), retornar para processamento
        if ($lastResponse) {
            return $lastResponse;
        }
        
        // Se não temos resposta, lançar a última exceção
        if ($lastException) {
            throw $lastException;
        }
        
        throw new \Exception("Todas as {$this->maxRetries} tentativas falharam para {$operation}");
    }
    
    /**
     * Verifica se um erro HTTP é recuperável (deve fazer retry)
     * 
     * @param int $statusCode Código HTTP
     * @return bool
     */
    private function isRetryableError(int $statusCode): bool
    {
        // Erros de servidor (5xx) são recuperáveis
        if ($statusCode >= 500) {
            return true;
        }
        
        // Rate limit (429) é recuperável
        if ($statusCode === 429) {
            return true;
        }
        
        // Timeout gateway (504) é recuperável
        if ($statusCode === 504) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Cria cliente HTTP configurado com timeout e retry
     * 
     * @return \Illuminate\Http\Client\PendingRequest
     */
    private function createHttpClient(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);
    }
}
