<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Asaas;

class AsaasService
{
    public string $baseUrl;
    public string $apiKey;
    public string $environment;
    public ?string $webhookToken = null;

    public function __construct()
    {
        $asaasConfig = Asaas::first();
        if ($asaasConfig) {
            $this->baseUrl = $asaasConfig->url;
            $this->apiKey = $asaasConfig->api_key;
            $this->environment = $asaasConfig->environment ?? 'sandbox';
            $this->webhookToken = $asaasConfig->webhook_token;
        } else {
            // Fallback para .env se não houver configuração no banco
            $this->environment = env('ASAAS_ENVIRONMENT', 'sandbox');
            $this->baseUrl = $this->environment === 'production' 
                ? 'https://api.asaas.com/v3/' 
                : 'https://api-sandbox.asaas.com/v3/';
            $this->apiKey = env('ASAAS_API_KEY');
            $this->webhookToken = env('ASAAS_WEBHOOK_TOKEN');
        }
    }

    /**
     * Cria uma cobrança PIX
     */
    public function createPixCharge($data)
    {
        try {
            $payload = [
                'customer' => $data['customer_id'] ?? $this->createCustomer($data),
                'billingType' => 'PIX',
                'value' => number_format($data['amount'], 2, '.', ''),
                'dueDate' => $data['due_date'] ?? date('Y-m-d', strtotime('+1 day')),
                'description' => $data['description'] ?? 'Pagamento via PIX',
                'externalReference' => $data['external_id'],
                'callback' => [
                    'successUrl' => $data['success_url'] ?? null,
                    'autoRedirect' => false
                ]
            ];

            // Remove campos nulos
            $payload = array_filter($payload, function($value) {
                return $value !== null;
            });

            Log::info('Asaas - Payload enviado: ' . json_encode($payload));
            Log::info('Asaas - URL: ' . $this->baseUrl . 'payments');
            Log::info('Asaas - API Key: ' . substr($this->apiKey, 0, 10) . '...');

            $response = Http::withHeaders([
                'access_token' => $this->apiKey,
                'Content-Type' => 'application/json'
            ])->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
                ]
            ])->post($this->baseUrl . 'payments', $payload);

            Log::info('Asaas - Status da resposta: ' . $response->status());
            Log::info('Asaas - Corpo da resposta: ' . $response->body());

            if ($response->successful()) {
                $responseData = $response->json();
                
                // Adiciona informações do PIX se disponível
                if (isset($responseData['pixTransaction']) && isset($responseData['pixTransaction']['qrCode'])) {
                    $responseData['qr_code'] = $responseData['pixTransaction']['qrCode'];
                    $responseData['qr_code_image_url'] = $this->generateQrCodeImage($responseData['pixTransaction']['qrCode']);
                }
                
                return $responseData;
            }

            Log::error('Erro ao criar cobrança PIX Asaas: ' . $response->body());
            return $this->handleError($response);

        } catch (\Exception $e) {
            Log::error('Exceção ao criar cobrança PIX Asaas: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Cria um cliente no Asaas
     */
    public function createCustomer($data)
    {
        try {
            $payload = [
                'name' => $data['customer_name'] ?? 'Cliente',
                'email' => $data['customer_email'] ?? 'cliente@email.com',
                'phone' => $data['customer_phone'] ?? '11999999999',
                'cpfCnpj' => $data['customer_document'] ?? '00000000000',
                'externalReference' => $data['customer_external_id'] ?? $data['external_id']
            ];

            $response = Http::withHeaders([
                'access_token' => $this->apiKey,
                'Content-Type' => 'application/json'
            ])->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
                ]
            ])->post($this->baseUrl . 'customers', $payload);

            if ($response->successful()) {
                $customerData = $response->json();
                return $customerData['id'];
            }

            Log::error('Erro ao criar cliente Asaas: ' . $response->body());
            return null;

        } catch (\Exception $e) {
            Log::error('Exceção ao criar cliente Asaas: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Realiza transferência PIX (Cash-out)
     */
    public function makePixTransfer($data)
    {
        try {
            $payload = [
                'value' => number_format($data['amount'], 2, '.', ''),
                'pixAddressKey' => $data['pix_key'],
                'description' => $data['description'] ?? 'Transferência PIX',
                'scheduleDate' => $data['schedule_date'] ?? date('Y-m-d'),
                'externalReference' => $data['external_id']
            ];

            Log::info('=== ASAAS TRANSFER PAYLOAD ENVIADO ===');
            Log::info('AsaasService::makePixTransfer - Payload JSON:', ['payload' => json_encode($payload, JSON_PRETTY_PRINT)]);
            Log::info('AsaasService::makePixTransfer - API Key:', ['api_key' => substr($this->apiKey, 0, 10) . '...']);
            Log::info('AsaasService::makePixTransfer - URL:', ['url' => $this->baseUrl . 'transfers']);
            Log::info('=== FIM ASAAS TRANSFER PAYLOAD ===');

            $response = Http::withHeaders([
                'access_token' => $this->apiKey,
                'Content-Type' => 'application/json'
            ])->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
                ]
            ])->post($this->baseUrl . 'transfers', $payload);

            Log::info('AsaasService::makePixTransfer - Response Status:', ['status' => $response->status()]);
            Log::info('AsaasService::makePixTransfer - Response Body:', ['body' => $response->body()]);

            if ($response->successful()) {
                return $response->json();
            }

            return $this->handleError($response);

        } catch (\Exception $e) {
            Log::error('Exceção ao realizar transferência PIX Asaas: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Consulta status de uma cobrança
     */
    public function getPaymentStatus($paymentId)
    {
        try {
            $response = Http::withHeaders([
                'access_token' => $this->apiKey,
                'Content-Type' => 'application/json'
            ])->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
                ]
            ])->get($this->baseUrl . 'payments/' . $paymentId);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Erro ao consultar status da cobrança Asaas: ' . $response->body());
            return false;

        } catch (\Exception $e) {
            Log::error('Exceção ao consultar status da cobrança Asaas: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Consulta status de uma transferência
     */
    public function getTransferStatus($transferId)
    {
        try {
            $response = Http::withHeaders([
                'access_token' => $this->apiKey,
                'Content-Type' => 'application/json'
            ])->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
                ]
            ])->get($this->baseUrl . 'transfers/' . $transferId);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Erro ao consultar status da transferência Asaas: ' . $response->body());
            return false;

        } catch (\Exception $e) {
            Log::error('Exceção ao consultar status da transferência Asaas: ' . $e->getMessage());
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
     * Trata erros da API
     */
    private function handleError($response)
    {
        $errorData = $response->json();
        Log::error('Erro na API Asaas', [
            'status' => $response->status(),
            'body' => $response->body(),
            'error_data' => $errorData
        ]);
        
        // Retornar o erro específico da API com mais detalhes
        $errorMessage = 'Erro desconhecido da API Asaas';
        
        if (isset($errorData['errors'])) {
            if (is_array($errorData['errors'])) {
                $errorMessage = implode(', ', array_column($errorData['errors'], 'description'));
            } else {
                $errorMessage = $errorData['errors'];
            }
        } elseif (isset($errorData['message'])) {
            $errorMessage = $errorData['message'];
        } elseif (isset($errorData['error'])) {
            $errorMessage = $errorData['error'];
        }
        
        return [
            'error' => true,
            'statusCode' => $response->status(),
            'message' => $errorMessage,
            'details' => $errorData,
            'raw_response' => $response->body()
        ];
    }

    /**
     * Valida webhook do Asaas
     */
    public function validateWebhook($payload, $signature)
    {
        if (!$this->webhookToken) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $this->webhookToken);
        return hash_equals($expectedSignature, $signature);
    }
}
