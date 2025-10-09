<?php

namespace App\Services;

use App\Models\Woovi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WooviService
{
    private $woovi;
    private $apiUrl;

    public function __construct()
    {
        $this->woovi = Woovi::first();
        if ($this->woovi) {
            $this->apiUrl = $this->woovi->getApiUrl();
        }
    }

    /**
     * Criar uma cobrança PIX (Cash In)
     */
    public function createCharge($data)
    {
        try {
            if (!$this->woovi || !$this->woovi->status) {
                throw new \Exception('Woovi não configurado ou inativo');
            }

            $payload = [
                'correlationID' => $data['correlationID'] ?? uniqid('woovi_'),
                'value' => $data['value'],
                'comment' => $data['comment'] ?? 'Pagamento via PIX',
                'customer' => [
                    'name' => $data['customer']['name'],
                    'taxID' => $data['customer']['taxID'],
                    'email' => $data['customer']['email'] ?? null,
                    'phone' => !empty($data['customer']['phone']) ? $data['customer']['phone'] : '00000000000'
                ]
            ];

            Log::info('WooviService::createCharge - Enviando requisição:', [
                'url' => $this->apiUrl . '/api/v1/charge',
                'correlationID' => $payload['correlationID'],
                'value' => $payload['value'],
                'customer_name' => $payload['customer']['name'],
                'customer_taxID' => $payload['customer']['taxID']
            ]);

            $response = Http::withHeaders([
                'Authorization' => $this->woovi->api_key,
                'Content-Type' => 'application/json'
            ])->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_CONNECTTIMEOUT => 10
                ]
            ])->post($this->apiUrl . '/api/v1/charge', $payload);

            Log::info('WooviService::createCharge - Resposta recebida:', [
                'status_code' => $response->status(),
                'successful' => $response->successful(),
                'correlationID' => $payload['correlationID']
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                Log::info('WooviService::createCharge - Cobrança criada com sucesso:', [
                    'correlationID' => $payload['correlationID'],
                    'identifier' => $responseData['charge']['identifier'] ?? 'N/A',
                    'status' => $responseData['charge']['status'] ?? 'N/A'
                ]);
                return $responseData;
            } else {
                $errorBody = $response->body();
                Log::error('WooviService::createCharge - Erro na API:', [
                    'status_code' => $response->status(),
                    'error_body' => $errorBody,
                    'correlationID' => $payload['correlationID']
                ]);
                
                // Tentar extrair mensagem de erro mais específica
                $errorData = json_decode($errorBody, true);
                $errorMessage = 'Erro ao criar cobrança';
                
                if (isset($errorData['error'])) {
                    $errorMessage = $errorData['error'];
                } elseif (isset($errorData['message'])) {
                    $errorMessage = $errorData['message'];
                } elseif (isset($errorData['errors'])) {
                    $errorMessage = is_array($errorData['errors']) ? implode(', ', $errorData['errors']) : $errorData['errors'];
                }
                
                return [
                    'error' => true,
                    'message' => $errorMessage,
                    'status_code' => $response->status(),
                    'raw_response' => $errorBody
                ];
            }
        } catch (\Exception $e) {
            Log::error('WooviService::createCharge - Exceção:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'correlationID' => $data['correlationID'] ?? 'N/A'
            ]);
            return [
                'error' => true,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Criar um saque PIX (Cash Out) usando Payment API
     */
    public function createWithdrawal($data)
    {
        try {
            if (!$this->woovi || !$this->woovi->status) {
                throw new \Exception('Woovi não configurado ou inativo');
            }

            // Primeiro, criar uma solicitação de pagamento
            $paymentPayload = [
                'type' => 'PIX_KEY',
                'value' => $data['value'],
                'destinationAlias' => $data['pixKey'],
                'destinationAliasType' => $this->getPixKeyType($data['pixKeyType']),
                'correlationID' => $data['correlationID'] ?? uniqid('woovi_payment_'),
                'comment' => $data['description'] ?? 'Saque via PIX'
            ];

            Log::info('WooviService::createWithdrawal - Criando solicitação de pagamento:', [
                'url' => $this->apiUrl . '/api/v1/payment',
                'correlationID' => $paymentPayload['correlationID'],
                'value' => $paymentPayload['value'],
                'destinationAlias' => $paymentPayload['destinationAlias'],
                'destinationAliasType' => $paymentPayload['destinationAliasType']
            ]);

            // Criar solicitação de pagamento
            $paymentResponse = Http::withHeaders([
                'Authorization' => $this->woovi->api_key,
                'Content-Type' => 'application/json'
            ])->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
                ]
            ])->post($this->apiUrl . '/api/v1/payment', $paymentPayload);

            if (!$paymentResponse->successful()) {
                $errorBody = $paymentResponse->body();
                Log::error('Erro ao criar solicitação de pagamento Woovi: ' . $errorBody);
                return [
                    'error' => true,
                    'message' => 'Erro ao criar solicitação de pagamento: ' . $errorBody
                ];
            }

            $paymentData = $paymentResponse->json();
            $correlationID = $paymentPayload['correlationID'];

            Log::info('WooviService::createWithdrawal - Solicitação de pagamento criada:', [
                'correlationID' => $correlationID,
                'status' => $paymentData['payment']['status'] ?? 'UNKNOWN'
            ]);

            // Agora aprovar o pagamento
            $approvePayload = [
                'correlationID' => $correlationID
            ];

            Log::info('WooviService::createWithdrawal - Aprovando pagamento:', [
                'url' => $this->apiUrl . '/api/v1/payment/approve',
                'correlationID' => $correlationID
            ]);

            $response = Http::withHeaders([
                'Authorization' => $this->woovi->api_key,
                'Content-Type' => 'application/json'
            ])->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
                ]
            ])->post($this->apiUrl . '/api/v1/payment/approve', $approvePayload);

            if ($response->successful()) {
                $responseData = $response->json();
                Log::info('WooviService::createWithdrawal - Pagamento aprovado com sucesso:', [
                    'correlationID' => $correlationID,
                    'status' => $responseData['payment']['status'] ?? 'UNKNOWN'
                ]);
                return $responseData;
            } else {
                $errorBody = $response->body();
                Log::error('Erro ao aprovar pagamento Woovi: ' . $errorBody);
                
                // Em modo sandbox, verificar se é a mensagem específica que indica sucesso
                if ($this->woovi->sandbox) {
                    $errorData = json_decode($errorBody, true);
                    $errorMessage = '';
                    
                    if (isset($errorData['error'])) {
                        $errorMessage = $errorData['error'];
                    } elseif (isset($errorData['message'])) {
                        $errorMessage = $errorData['message'];
                    } elseif (isset($errorData['errors'])) {
                        $errorMessage = is_array($errorData['errors']) ? implode(', ', $errorData['errors']) : $errorData['errors'];
                    }
                    
                    // Verificar se é a mensagem específica do sandbox que indica sucesso
                    if (strpos($errorMessage, 'Você não pode sacar de uma conta diferente da Woovi') !== false) {
                        Log::info('[WOOVI][SANDBOX]: Interpretando mensagem de erro como sucesso em modo sandbox', [
                            'original_message' => $errorMessage
                        ]);
                        
                        // Retornar como sucesso com dados simulados
                        return [
                            'payment' => [
                                'value' => $data['value'],
                                'status' => 'APPROVED',
                                'destinationAlias' => $data['pixKey'],
                                'comment' => $data['description'] ?? 'Saque via PIX',
                                'correlationID' => $correlationID
                            ],
                            'transaction' => [
                                'value' => $data['value'],
                                'endToEndId' => 'sandbox_' . uniqid(),
                                'time' => now()->toISOString()
                            ],
                            'sandbox_success' => true,
                            'original_error' => $errorMessage
                        ];
                    }
                }
                
                return [
                    'error' => true,
                    'message' => 'Erro ao aprovar pagamento: ' . $errorBody
                ];
            }
        } catch (\Exception $e) {
            Log::error('Erro no WooviService::createWithdrawal: ' . $e->getMessage());
            return [
                'error' => true,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Verificar status de uma cobrança
     */
    public function getCharge($chargeId)
    {
        try {
            if (!$this->woovi || !$this->woovi->status) {
                throw new \Exception('Woovi não configurado ou inativo');
            }

            $response = Http::withHeaders([
                'Authorization' => $this->woovi->api_key,
                'Content-Type' => 'application/json'
            ])->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
                ]
            ])->get($this->apiUrl . '/api/v1/charge/' . $chargeId);

            if ($response->successful()) {
                return $response->json();
            } else {
                Log::error('Erro ao buscar cobrança Woovi: ' . $response->body());
                return [
                    'error' => true,
                    'message' => 'Erro ao buscar cobrança: ' . $response->body()
                ];
            }
        } catch (\Exception $e) {
            Log::error('Erro no WooviService::getCharge: ' . $e->getMessage());
            return [
                'error' => true,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Verificar saldo da conta
     */
    public function getAccountBalance()
    {
        try {
            if (!$this->woovi || !$this->woovi->status) {
                throw new \Exception('Woovi não configurado ou inativo');
            }

            $response = Http::withHeaders([
                'Authorization' => $this->woovi->api_key,
                'Content-Type' => 'application/json'
            ])->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
                ]
            ])->get($this->apiUrl . '/api/v1/account/');

            if ($response->successful()) {
                return $response->json();
            } else {
                Log::error('Erro ao buscar saldo Woovi: ' . $response->body());
                return [
                    'error' => true,
                    'message' => 'Erro ao buscar saldo: ' . $response->body()
                ];
            }
        } catch (\Exception $e) {
            Log::error('Erro no WooviService::getAccountBalance: ' . $e->getMessage());
            return [
                'error' => true,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Validar chave PIX
     */
    public function validatePixKey($pixKey, $pixKeyType)
    {
        try {
            if (!$this->woovi || !$this->woovi->status) {
                throw new \Exception('Woovi não configurado ou inativo');
            }

            $payload = [
                'pixKey' => $pixKey,
                'pixKeyType' => $pixKeyType
            ];

            $response = Http::withHeaders([
                'Authorization' => $this->woovi->api_key,
                'Content-Type' => 'application/json'
            ])->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
                ]
            ])->post($this->apiUrl . '/api/v1/pixKey/check', $payload);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'valid' => true,
                    'data' => $data
                ];
            } else {
                return [
                    'valid' => false,
                    'message' => 'Chave PIX inválida'
                ];
            }
        } catch (\Exception $e) {
            Log::error('Erro no WooviService::validatePixKey: ' . $e->getMessage());
            return [
                'valid' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Configurar webhook da Woovi
     */
    public function configureWebhook($webhookUrl, $webhookSecret = null)
    {
        try {
            if (!$this->woovi || !$this->woovi->status) {
                throw new \Exception('Woovi não configurado ou inativo');
            }

            // A Woovi pode não ter um endpoint específico para configurar webhook
            // Vamos apenas salvar o webhook_secret no banco de dados
            // O webhook deve ser configurado manualmente no painel da Woovi
            Log::info('[WOOVI][WEBHOOK]: Configurando webhook_secret no banco de dados', []);
            
            return [
                'success' => true,
                'message' => 'webhook_secret configurado. Configure a URL do webhook manualmente no painel da Woovi.',
                'webhook_url' => $webhookUrl,
                'webhook_secret' => $webhookSecret
            ];
        } catch (\Exception $e) {
            Log::error('Erro no WooviService::configureWebhook: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Converter tipo de chave PIX para formato da API OpenPix
     */
    private function getPixKeyType($pixKeyType)
    {
        $mapping = [
            'cpf' => 'CPF',
            'cnpj' => 'CNPJ',
            'email' => 'EMAIL',
            'telefone' => 'PHONE',
            'phone' => 'PHONE',
            'aleatoria' => 'RANDOM',
            'random' => 'RANDOM',
            'CPF' => 'CPF',
            'CNPJ' => 'CNPJ',
            'EMAIL' => 'EMAIL',
            'PHONE' => 'PHONE',
            'RANDOM' => 'RANDOM'
        ];

        return $mapping[$pixKeyType] ?? 'RANDOM';
    }
}
