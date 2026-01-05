<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\PagArm;

class PagArmService
{
    public string $baseUrl;
    public string $clientId;
    public string $clientSecret;
    public string $apiKey;
    public string $environment;
    public ?string $token = null;

    public function __construct()
    {
        $pagarmConfig = PagArm::first();
        if ($pagarmConfig) {
            $this->baseUrl = $pagarmConfig->url;
            $this->clientId = $pagarmConfig->client_id;
            $this->clientSecret = $pagarmConfig->client_secret;
            $this->apiKey = $pagarmConfig->api_key;
            $this->environment = $pagarmConfig->environment;
        } else {
            // Fallback para .env se não houver configuração no banco
            $this->baseUrl = env('PAGARM_BASE_URL', 'https://api.pagarm.com.br/v1');
            $this->clientId = env('PAGARM_CLIENT_ID');
            $this->clientSecret = env('PAGARM_CLIENT_SECRET');
            $this->apiKey = env('PAGARM_API_KEY');
            $this->environment = env('PAGARM_ENVIRONMENT', 'sandbox');
        }
    }

    /**
     * Gera token de acesso para autenticação na API
     */
    public function generateToken()
    {
        try {
            Log::info('=== PAGARM GERAÇÃO DE TOKEN ===');
            Log::info('PagArmService::generateToken - URL:', ['url' => $this->baseUrl . '/auth/token']);
            Log::info('PagArmService::generateToken - Client ID:', ['client_id' => $this->clientId]);
            
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-API-Key' => $this->apiKey
            ])->post($this->baseUrl . '/auth/token', [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials'
            ]);

            Log::info('PagArmService::generateToken - Response Status:', ['status' => $response->status()]);
            Log::info('PagArmService::generateToken - Response Body:', ['body' => $response->body()]);

            if ($response->successful()) {
                $data = $response->json();
                $this->token = $data['access_token'];
                Log::info('PagArmService::generateToken - Token gerado com sucesso');
                return $this->token;
            }

            Log::error('Erro ao gerar token PagArm: ' . $response->body());
            return false;

        } catch (\Exception $e) {
            Log::error('Exceção ao gerar token PagArm: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gera QR Code para pagamento PIX (Cash-in)
     */
    public function generateQrCode($data)
    {
        try {
            if (!$this->token) {
                $this->generateToken();
            }

            $payload = [
                'amount' => $data['amount'],
                'external_id' => $data['external_id'],
                'postback_url' => $data['postback_url'],
                'description' => $data['description'] ?? 'Pagamento via PIX',
                'expiration_time' => 3600,
                'payer' => [
                    'name' => $data['debtor_name'] ?? 'Cliente',
                    'document' => $data['debtor_document_number'] ?? '00000000000',
                    'email' => $data['email'] ?? 'cliente@pagarm.com.br',
                    'phone' => $data['phone'] ?? '11999999999'
                ]
            ];

            Log::info('=== PAGARM QR CODE PAYLOAD ===');
            Log::info('PagArmService::generateQrCode - Payload:', $payload);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json',
                'X-API-Key' => $this->apiKey
            ])->post($this->baseUrl . '/pix/qrcode', $payload);

            Log::info('PagArmService::generateQrCode - Response Status:', ['status' => $response->status()]);
            Log::info('PagArmService::generateQrCode - Response Body:', ['body' => $response->body()]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Erro ao gerar QR Code PagArm: ' . $response->body());
            return false;

        } catch (\Exception $e) {
            Log::error('Exceção ao gerar QR Code PagArm: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Processa pagamento PIX (Cash-out)
     */
    public function makePayment($data)
    {
        try {
            if (!$this->token) {
                $this->generateToken();
            }

            // Validar e formatar chave PIX
            $pixKey = $this->formatPixKey($data['pix_key'], $data['pix_key_type'] ?? 'CPF');
            $pixKeyType = $this->validatePixKeyType($data['pix_key_type'] ?? 'CPF');

            $payload = [
                'amount' => (float) $data['amount'],
                'description' => $data['description'] ?? 'Pagamento via PIX',
                'external_id' => $data['external_id'],
                'postback_url' => $data['postback_url'],
                'beneficiary' => [
                    'name' => $data['beneficiary_name'] ?? '',
                    'key_type' => $pixKeyType,
                    'key' => $pixKey,
                    'tax_id' => $data['beneficiary_document'] ?? $pixKey
                ]
            ];

            Log::info('=== PAGARM PAYMENT PAYLOAD ===');
            Log::info('PagArmService::makePayment - Payload:', $payload);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json',
                'X-API-Key' => $this->apiKey
            ])->post($this->baseUrl . '/pix/payment', $payload);

            Log::info('PagArmService::makePayment - Response Status:', ['status' => $response->status()]);
            Log::info('PagArmService::makePayment - Response Body:', ['body' => $response->body()]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Erro ao processar pagamento PagArm: ' . $response->body());
            return false;

        } catch (\Exception $e) {
            Log::error('Exceção ao processar pagamento PagArm: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Formata chave PIX baseada no tipo
     */
    private function formatPixKey($pixKey, $pixKeyType)
    {
        switch (strtolower($pixKeyType)) {
            case 'cpf':
            case 'cnpj':
            case 'telefone':
            case 'phone':
                return preg_replace('/[^0-9]/', '', $pixKey);
            case 'email':
                return strtolower(trim($pixKey));
            case 'aleatoria':
            case 'random':
                return $pixKey;
            default:
                return $pixKey;
        }
    }

    /**
     * Valida tipo de chave PIX
     */
    private function validatePixKeyType($pixKeyType)
    {
        $validTypes = ['CPF', 'CNPJ', 'EMAIL', 'PHONE', 'RANDOM'];
        $type = strtoupper($pixKeyType);
        
        if (in_array($type, $validTypes)) {
            return $type;
        }
        
        return 'CPF'; // Fallback
    }

    /**
     * Consulta status de uma transação
     */
    public function getTransactionStatus($transactionId)
    {
        try {
            if (!$this->token) {
                $this->generateToken();
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'X-API-Key' => $this->apiKey
            ])->get($this->baseUrl . '/transactions/' . $transactionId);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Erro ao consultar status PagArm: ' . $response->body());
            return false;

        } catch (\Exception $e) {
            Log::error('Exceção ao consultar status PagArm: ' . $e->getMessage());
            return false;
        }
    }
}


