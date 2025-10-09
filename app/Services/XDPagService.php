<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\XDPag;

class XDPagService
{
    public string $baseUrl;
    public string $clientId;
    public string $clientSecret;
    public ?string $token = null;

    public function __construct()
    {
        $xdpagConfig = XDPag::first();
        if ($xdpagConfig) {
            $this->baseUrl = $xdpagConfig->url;
            $this->clientId = $xdpagConfig->client_id;
            $this->clientSecret = $xdpagConfig->client_secret;
        } else {
            // Fallback para .env se não houver configuração no banco
            $this->baseUrl = env('XDPAG_BASE_URL', 'https://api.xdpag.com');
            $this->clientId = env('XDPAG_CLIENT_ID');
            $this->clientSecret = env('XDPAG_CLIENT_SECRET');
        }
    }

    /**
     * Gera token de acesso para autenticação na API
     */
    public function generateToken()
    {
        try {
            Log::info('=== XDPAG GERAÇÃO DE TOKEN ===');
            Log::info('XDPagService::generateToken - URL:', ['url' => $this->baseUrl . '/api/account/login']);
            Log::info('XDPagService::generateToken - Username:', ['username' => $this->clientId]);
            
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ])->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
                ]
            ])->post($this->baseUrl . '/api/account/login', [
                'username' => $this->clientId,
                'password' => $this->clientSecret
            ]);

            Log::info('XDPagService::generateToken - Response Status:', ['status' => $response->status()]);
            Log::info('XDPagService::generateToken - Response Body:', ['body' => $response->body()]);
            Log::info('=== FIM XDPAG GERAÇÃO DE TOKEN ===');

            if ($response->successful()) {
                $data = $response->json();
                $this->token = $data['access_token'];
                Log::info('XDPagService::generateToken - Token gerado com sucesso:', ['token_length' => strlen($this->token)]);
                return $this->token;
            }

            Log::error('Erro ao gerar token XDPag: ' . $response->body());
            return false;

        } catch (\Exception $e) {
            Log::error('Exceção ao gerar token XDPag: ' . $e->getMessage());
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
                'amount' => (string) $data['amount'],
                'webhook' => $data['postback_url'],
                'externalId' => $data['external_id'],
                'description' => $data['description'] ?? 'Pagamento via PIX',
                'additional_data' => [
                    [
                        'name' => 'Cliente',
                        'value' => $data['debtor_name'] ?? 'Cliente'
                    ],
                    [
                        'name' => 'Documento',
                        'value' => $data['debtor_document_number'] ?? '67207393792'
                    ],
                    [
                        'name' => 'Email',
                        'value' => $data['email'] ?? 'cliente@hkpay.shop'
                    ],
                    [
                        'name' => 'Telefone',
                        'value' => $data['phone'] ?? '11999999999'
                    ]
                ]
            ];

            Log::info('XDPag - Payload enviado: ' . json_encode($payload));
            Log::info('XDPag - URL: ' . $this->baseUrl . '/api/order/pay-in');
            Log::info('XDPag - Token: ' . $this->token);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ])->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
                ]
            ])->post($this->baseUrl . '/api/order/pay-in', $payload);

            Log::info('XDPag - Status da resposta: ' . $response->status());
            Log::info('XDPag - Corpo da resposta: ' . $response->body());

            if ($response->successful()) {
                $responseData = $response->json();
                
                // Adaptar resposta da XDPag para o formato esperado
                $qrcode = $responseData['data']['qrcode'] ?? null;
                $brcode = $responseData['data']['brcode'] ?? null;
                
                // Verificar se qrcode é base64 de imagem
                if ($qrcode && strpos($qrcode, 'data:image') === 0) {
                    Log::warning('XDPag - qrcode contém base64 de imagem, usando brcode como código PIX');
                    // Se qrcode for base64 de imagem, usar brcode como código PIX
                    $formattedData = [
                        'id' => $responseData['data']['id'] ?? null,
                        'status' => $responseData['data']['status'] ?? null,
                        'external_id' => $responseData['data']['externalId'] ?? null,
                        'qrcode' => $brcode, // Usar brcode como código PIX
                        'brcode' => $brcode,
                        'qr_code_image_url' => $qrcode // Usar qrcode como URL da imagem
                    ];
                } else {
                    // Se qrcode não for base64, usar normalmente
                    $formattedData = [
                        'id' => $responseData['data']['id'] ?? null,
                        'status' => $responseData['data']['status'] ?? null,
                        'external_id' => $responseData['data']['externalId'] ?? null,
                        'qrcode' => $qrcode,
                        'brcode' => $brcode,
                        'qr_code_image_url' => $this->generateQrCodeImage($qrcode ?? '')
                    ];
                }
                
                return $formattedData;
            }

            Log::error('Erro ao gerar QR Code XDPag: ' . $response->body());
            return false;

        } catch (\Exception $e) {
            Log::error('Exceção ao gerar QR Code XDPag: ' . $e->getMessage());
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
                'webhook' => $data['postback_url'],
                'document' => $data['beneficiary_document'] ?? $pixKey,
                'pixKey' => $pixKey,
                'pixKeyType' => strtoupper($pixKeyType),
                'externalId' => $data['external_id'],
                'validate_document' => false
            ];

            Log::info('=== XDPAG PAYLOAD ENVIADO ===');
            Log::info('XDPagService::makePayment - URL:', ['url' => $this->baseUrl . '/api/order/pay-out']);
            Log::info('XDPagService::makePayment - Headers:', [
                'Authorization' => 'Bearer ' . substr($this->token, 0, 20) . '...',
                'Content-Type' => 'application/json'
            ]);
            Log::info('XDPagService::makePayment - Payload Completo:', [
                'amount' => $payload['amount'],
                'webhook' => $payload['webhook'],
                'document' => $payload['document'],
                'pixKey' => $payload['pixKey'],
                'pixKeyType' => $payload['pixKeyType'],
                'externalId' => $payload['externalId'],
                'validate_document' => $payload['validate_document']
            ]);
            Log::info('XDPagService::makePayment - Payload JSON Raw:', ['payload' => json_encode($payload, JSON_PRETTY_PRINT)]);
            Log::info('=== FIM XDPAG PAYLOAD ===');

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json'
            ])->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
                ]
            ])->post($this->baseUrl . '/api/order/pay-out', $payload);

            Log::info('=== XDPAG RESPOSTA DO SERVIDOR ===');
            Log::info('XDPagService::makePayment - Response Status:', ['status' => $response->status()]);
            Log::info('XDPagService::makePayment - Response Headers:', ['headers' => $response->headers()]);
            Log::info('XDPagService::makePayment - Response Body Raw:', ['body' => $response->body()]);
            
            // Tentar decodificar JSON da resposta
            $responseData = $response->json();
            if ($responseData) {
                Log::info('XDPagService::makePayment - Response JSON Decodificado:', ['response_data' => $responseData]);
            }
            Log::info('=== FIM XDPAG RESPOSTA ===');

            if ($response->successful()) {
                return $response->json();
            }

            $errorData = $response->json();
            Log::error('Erro ao realizar pagamento XDPag', [
                'status' => $response->status(),
                'body' => $response->body(),
                'error_data' => $errorData
            ]);
            
            // Retornar o erro específico da API com mais detalhes
            $errorMessage = 'Erro desconhecido da API XDPag';
            
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
            Log::error('Exceção ao realizar pagamento XDPag: ' . $e->getMessage());
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
            case 'EVP':
                // Chaves aleatórias (EVP) não precisam de formatação especial
                return $pixKey;
        }
        
        return $pixKey;
    }

    /**
     * Valida e normaliza o tipo de chave PIX
     */
    private function validatePixKeyType($pixKeyType)
    {
        $validTypes = ['CPF', 'CNPJ', 'EMAIL', 'PHONE', 'EVP'];
        $normalizedType = strtoupper(trim($pixKeyType));
        
        if (in_array($normalizedType, $validTypes)) {
            return $normalizedType;
        }
        
        // Mapear tipos comuns para os tipos aceitos pela XDPag
        $typeMapping = [
            'CPF' => 'CPF',
            'CNPJ' => 'CNPJ',
            'EMAIL' => 'EMAIL',
            'PHONE' => 'PHONE',
            'TELEFONE' => 'PHONE',
            'RANDOM' => 'EVP',
            'ALEATORIA' => 'EVP',
            'CRYPTO' => 'EVP',
            'CRIPTO' => 'EVP'
        ];
        
        return $typeMapping[$normalizedType] ?? 'CPF';
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
            ])->get($this->baseUrl . '/api/order/' . $transactionId);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Erro ao verificar status da transação XDPag: ' . $response->body());
            return false;

        } catch (\Exception $e) {
            Log::error('Exceção ao verificar status da transação XDPag: ' . $e->getMessage());
            return false;
        }
    }
}
