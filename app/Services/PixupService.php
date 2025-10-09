<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Pixup;

class PixupService
{
    public string $baseUrl;
    public string $clientId;
    public string $clientSecret;
    public ?string $token = null;

    public function __construct()
    {
        $pixupConfig = Pixup::first();
        if ($pixupConfig) {
            $this->baseUrl = $pixupConfig->url;
            $this->clientId = $pixupConfig->client_id;
            $this->clientSecret = $pixupConfig->client_secret;
        } else {
            // Fallback para .env se não houver configuração no banco
            $this->baseUrl = env('PIXUP_BASE_URL', 'https://api.pixupbr.com/v2/');
            $this->clientId = env('PIXUP_CLIENT_ID');
            $this->clientSecret = env('PIXUP_CLIENT_SECRET');
        }
    }

    /**
     * Gera token de acesso para autenticação na API
     */
    public function generateToken()
    {
        try {
            $credentials = $this->clientId . ':' . $this->clientSecret;
            $base64Credentials = base64_encode($credentials);
            
            Log::info('=== PIXUP GERAÇÃO DE TOKEN ===');
            Log::info('PixupService::generateToken - URL:', ['url' => $this->baseUrl . 'oauth/token']);
            Log::info('PixupService::generateToken - Client ID:', ['client_id' => $this->clientId]);
            Log::info('PixupService::generateToken - Headers:', [
                'Authorization' => 'Basic ' . substr($base64Credentials, 0, 20) . '...',
                'Content-Type' => 'application/x-www-form-urlencoded'
            ]);
            Log::info('PixupService::generateToken - Payload:', ['grant_type' => 'client_credentials']);
            
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $base64Credentials,
                'Content-Type' => 'application/x-www-form-urlencoded'
            ])->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
                ]
            ])->post($this->baseUrl . 'oauth/token', [
                'grant_type' => 'client_credentials'
            ]);

            Log::info('PixupService::generateToken - Response Status:', ['status' => $response->status()]);
            Log::info('PixupService::generateToken - Response Body:', ['body' => $response->body()]);
            Log::info('=== FIM PIXUP GERAÇÃO DE TOKEN ===');

            if ($response->successful()) {
                $data = $response->json();
                $this->token = $data['access_token'];
                Log::info('PixupService::generateToken - Token gerado com sucesso:', ['token_length' => strlen($this->token)]);
                return $this->token;
            }

            Log::error('Erro ao gerar token Pixup: ' . $response->body());
            return false;

        } catch (\Exception $e) {
            Log::error('Exceção ao gerar token Pixup: ' . $e->getMessage());
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
                'postbackUrl' => $data['postback_url'],
                'description' => $data['description'] ?? 'Pagamento via PIX',
                'payer' => [
                    'name' => $data['debtor_name'] ?? 'Cliente',
                    'document' => $data['debtor_document_number'] ?? '67207393792',
                    'email' => $data['email'] ?? 'cliente@hkpay.shop',
                    'phone' => $data['phone'] ?? '11999999999'
                ]
            ];

            Log::info('Pixup - Payload enviado: ' . json_encode($payload));
            Log::info('Pixup - URL: ' . $this->baseUrl . 'pix/qrcode');
            Log::info('Pixup - Token: ' . $this->token);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json'
            ])->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
                ]
            ])->post($this->baseUrl . 'pix/qrcode', $payload);

            Log::info('Pixup - Status da resposta: ' . $response->status());
            Log::info('Pixup - Corpo da resposta: ' . $response->body());

            if ($response->successful()) {
                $data = $response->json();
                
                // Se não tiver qr_code_image_url, gerar a partir do qrcode
                if (isset($data['qrcode']) && !isset($data['qr_code_image_url'])) {
                    $data['qr_code_image_url'] = $this->generateQrCodeImage($data['qrcode']);
                }
                
                return $data;
            }

            Log::error('Erro ao gerar QR Code Pixup: ' . $response->body());
            return false;

        } catch (\Exception $e) {
            Log::error('Exceção ao gerar QR Code Pixup: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gera URL da imagem do QR Code usando serviço externo
     */
    private function generateQrCodeImage($qrcode)
    {
        try {
            // Usar serviço gratuito para gerar QR Code
            $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($qrcode);
            
            // Verificar se a URL é válida
            $response = Http::withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
                ]
            ])->head($qrCodeUrl);
            if ($response->successful()) {
                return $qrCodeUrl;
            }
            
            // Fallback para outro serviço
            return 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($qrcode);
            
        } catch (\Exception $e) {
            Log::error('Erro ao gerar imagem do QR Code: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Realiza pagamento PIX (Cash-out)
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
                'postbackUrl' => $data['postback_url'],
                'creditParty' => [
                    'name' => $data['beneficiary_name'] ?? '',
                    'keyType' => $pixKeyType,
                    'key' => $pixKey,
                    'taxId' => $data['beneficiary_document'] ?? $pixKey
                ]
            ];

            Log::info('=== PIXUP PAYLOAD ENVIADO ===');
            Log::info('PixupService::makePayment - URL:', ['url' => $this->baseUrl . 'pix/payment']);
            Log::info('PixupService::makePayment - Headers:', [
                'Authorization' => 'Bearer ' . substr($this->token, 0, 20) . '...',
                'Content-Type' => 'application/json'
            ]);
            Log::info('PixupService::makePayment - Payload Completo:', [
                'amount' => $payload['amount'],
                'description' => $payload['description'],
                'external_id' => $payload['external_id'],
                'postbackUrl' => $payload['postbackUrl'],
                'creditParty' => $payload['creditParty']
            ]);
            Log::info('PixupService::makePayment - Payload JSON Raw:', ['payload' => json_encode($payload, JSON_PRETTY_PRINT)]);
            Log::info('=== FIM PIXUP PAYLOAD ===');

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json'
            ])->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
                ]
            ])->post($this->baseUrl . 'pix/payment', $payload);

            Log::info('=== PIXUP RESPOSTA DO SERVIDOR ===');
            Log::info('PixupService::makePayment - Response Status:', ['status' => $response->status()]);
            Log::info('PixupService::makePayment - Response Headers:', ['headers' => $response->headers()]);
            Log::info('PixupService::makePayment - Response Body Raw:', ['body' => $response->body()]);
            
            // Tentar decodificar JSON da resposta
            $responseData = $response->json();
            if ($responseData) {
                Log::info('PixupService::makePayment - Response JSON Decodificado:', ['response_data' => $responseData]);
            }
            Log::info('=== FIM PIXUP RESPOSTA ===');

            if ($response->successful()) {
                return $response->json();
            }

            $errorData = $response->json();
            Log::error('Erro ao realizar pagamento Pixup', [
                'status' => $response->status(),
                'body' => $response->body(),
                'error_data' => $errorData
            ]);
            
            // Retornar o erro específico da API com mais detalhes
            $errorMessage = 'Erro desconhecido da API Pixup';
            
            if (isset($errorData['message'])) {
                $errorMessage = $errorData['message'];
            } elseif (isset($errorData['error'])) {
                $errorMessage = $errorData['error'];
            } elseif (isset($errorData['errors'])) {
                if (is_array($errorData['errors'])) {
                    $errorMessage = implode(', ', $errorData['errors']);
                } else {
                    $errorMessage = $errorData['errors'];
                }
            } elseif (isset($errorData['detail'])) {
                $errorMessage = $errorData['detail'];
            }
            
            return [
                'error' => true,
                'statusCode' => $response->status(),
                'message' => $errorMessage,
                'details' => $errorData,
                'raw_response' => $response->body()
            ];

        } catch (\Exception $e) {
            Log::error('Exceção ao realizar pagamento Pixup: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Formata chave PIX de acordo com o tipo
     */
    private function formatPixKey($pixKey, $pixKeyType)
    {
        $pixKey = trim($pixKey);
        
        switch (strtoupper($pixKeyType)) {
            case 'CPF':
            case 'CNPJ':
            case 'PHONE':
                // Remove caracteres não numéricos
                return preg_replace('/[^0-9]/', '', $pixKey);
            case 'EMAIL':
                // Valida formato de email
                if (filter_var($pixKey, FILTER_VALIDATE_EMAIL)) {
                    return strtolower($pixKey);
                }
                break;
            case 'RANDOM':
            case 'CRYPTO':
                // Chaves aleatórias não precisam de formatação especial
                return $pixKey;
        }
        
        return $pixKey;
    }

    /**
     * Valida e normaliza o tipo de chave PIX
     */
    private function validatePixKeyType($pixKeyType)
    {
        $validTypes = ['cpf', 'cnpj', 'email', 'phone', 'random', 'crypto'];
        $normalizedType = strtolower(trim($pixKeyType));
        
        if (in_array($normalizedType, $validTypes)) {
            return $normalizedType;
        }
        
        // Mapear tipos comuns
        $typeMapping = [
            'telefone' => 'phone',
            'aleatoria' => 'random',
            'cripto' => 'crypto'
        ];
        
        return $typeMapping[$normalizedType] ?? 'cpf';
    }

    /**
     * Verifica status de uma transação
     */
    public function checkTransactionStatus($transactionId)
    {
        try {
            if (!$this->token) {
                $this->generateToken();
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json'
            ])->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
                ]
            ])->get($this->baseUrl . 'pix/transaction/' . $transactionId);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Erro ao verificar status da transação Pixup: ' . $response->body());
            return false;

        } catch (\Exception $e) {
            Log::error('Exceção ao verificar status da transação Pixup: ' . $e->getMessage());
            return false;
        }
    }
}