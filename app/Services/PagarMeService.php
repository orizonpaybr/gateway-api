<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Pagarme;
use App\Models\UserCard;

/**
 * Service para integração com Pagar.me API V5
 * 
 * Documentação: https://docs.pagar.me/reference/cartão-de-crédito-1
 * 
 * Suporta:
 * - Pagamentos com cartão de crédito
 * - Tokenização de cartões
 * - Autenticação 3D Secure
 * - Gerenciamento de clientes
 */
class PagarMeService
{
    public string $baseUrl;
    public ?string $secretKey = null;
    public ?string $publicKey = null;
    public string $environment;
    public ?string $webhookSecret = null;
    
    // Taxas de cartão
    public float $cardTxPercent = 0;
    public float $cardTxFixed = 0;
    public int $cardDaysAvailability = 30;

    private const SANDBOX_URL = 'https://api.pagar.me/core/v5/';
    private const PRODUCTION_URL = 'https://api.pagar.me/core/v5/';

    public function __construct()
    {
        $pagarmeConfig = Pagarme::first();
        
        if ($pagarmeConfig) {
            $this->secretKey = $pagarmeConfig->secret;
            $this->publicKey = $pagarmeConfig->public_key ?? null;
            $this->environment = $pagarmeConfig->environment ?? 'sandbox';
            $this->webhookSecret = $pagarmeConfig->webhook_secret ?? null;
            $this->baseUrl = $pagarmeConfig->url ?? self::PRODUCTION_URL;
            
            // Taxas de cartão
            $this->cardTxPercent = (float) ($pagarmeConfig->card_tx_percent ?? 0);
            $this->cardTxFixed = (float) ($pagarmeConfig->card_tx_fixed ?? 0);
            $this->cardDaysAvailability = (int) ($pagarmeConfig->card_days_availability ?? 30);
        } else {
            // Fallback para .env
            $this->environment = env('PAGARME_ENVIRONMENT', 'sandbox');
            $this->baseUrl = $this->environment === 'production' 
                ? self::PRODUCTION_URL 
                : self::SANDBOX_URL;
            $this->secretKey = env('PAGARME_SECRET_KEY');
            $this->publicKey = env('PAGARME_PUBLIC_KEY');
            $this->webhookSecret = env('PAGARME_WEBHOOK_SECRET');
        }
    }

    /**
     * Verifica se o serviço está configurado
     */
    public function isConfigured(): bool
    {
        return !empty($this->secretKey);
    }

    /**
     * Retorna headers de autenticação Basic Auth
     */
    private function getAuthHeaders(): array
    {
        $auth = base64_encode($this->secretKey . ':');
        
        return [
            'Authorization' => 'Basic ' . $auth,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Cria um pedido com pagamento via cartão de crédito
     * 
     * @param array $data Dados do pedido
     * @return array|null
     */
    public function createCardOrder(array $data): ?array
    {
        try {
            $payload = $this->buildCardOrderPayload($data);
            
            Log::info('PagarMeService::createCardOrder - Payload:', [
                'payload' => $this->sanitizeLogData($payload)
            ]);

            $response = Http::withHeaders($this->getAuthHeaders())
                ->timeout(30)
                ->post($this->baseUrl . 'orders', $payload);

            Log::info('PagarMeService::createCardOrder - Response:', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            if ($response->successful()) {
                $result = $response->json();
                Log::info('PagarMeService::createCardOrder - Pedido criado com sucesso:', [
                    'order_id' => $result['id'] ?? 'N/A',
                    'status' => $result['status'] ?? 'N/A'
                ]);
                return $result;
            }

            return $this->handleError($response);

        } catch (\Exception $e) {
            Log::error('PagarMeService::createCardOrder - Exceção:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Constrói o payload para pedido com cartão
     */
    private function buildCardOrderPayload(array $data): array
    {
        $amountInCents = (int) ($data['amount'] * 100);
        
        $payload = [
            'items' => [[
                'amount' => $amountInCents,
                'description' => $data['description'] ?? 'Depósito via cartão de crédito',
                'quantity' => 1,
                'code' => $data['external_id'] ?? uniqid('CARD_')
            ]],
            'customer' => $this->buildCustomerPayload($data),
            'payments' => [[
                'payment_method' => 'credit_card',
                'credit_card' => $this->buildCreditCardPayload($data, $amountInCents)
            ]]
        ];

        // Adicionar metadata se fornecido
        if (!empty($data['metadata'])) {
            $payload['metadata'] = $data['metadata'];
        }

        return $payload;
    }

    /**
     * Constrói payload do cliente
     */
    private function buildCustomerPayload(array $data): array
    {
        $customer = [
            'name' => $data['customer_name'] ?? 'Cliente',
            'email' => $data['customer_email'] ?? 'cliente@email.com',
            'type' => $this->getCustomerType($data['customer_document'] ?? ''),
            'document' => preg_replace('/\D/', '', $data['customer_document'] ?? '00000000000'),
            'document_type' => strlen(preg_replace('/\D/', '', $data['customer_document'] ?? '')) === 14 ? 'CNPJ' : 'CPF',
        ];

        // Adicionar telefone se disponível
        if (!empty($data['customer_phone'])) {
            $phone = $this->parsePhone($data['customer_phone']);
            $customer['phones'] = [
                'mobile_phone' => [
                    'country_code' => '55',
                    'area_code' => $phone['area_code'],
                    'number' => $phone['number']
                ]
            ];
        }

        return $customer;
    }

    /**
     * Constrói payload do cartão de crédito
     */
    private function buildCreditCardPayload(array $data, int $amountInCents): array
    {
        $creditCard = [
            'installments' => (int) ($data['installments'] ?? 1),
            'statement_descriptor' => substr($data['statement_descriptor'] ?? 'GATEWAY', 0, 13),
        ];

        // Prioridade: card_id > card_token > dados do cartão
        if (!empty($data['card_id'])) {
            // Usar cartão salvo
            $creditCard['card_id'] = $data['card_id'];
        } elseif (!empty($data['card_token'])) {
            // Usar token do cartão (gerado pelo Tokenizecard JS)
            $creditCard['card_token'] = $data['card_token'];
        } elseif (!empty($data['card'])) {
            // Dados completos do cartão (não recomendado - usar tokenização)
            $creditCard['card'] = [
                'number' => preg_replace('/\D/', '', $data['card']['number']),
                'holder_name' => strtoupper($data['card']['holder_name']),
                'exp_month' => (int) $data['card']['exp_month'],
                'exp_year' => (int) $data['card']['exp_year'],
                'cvv' => $data['card']['cvv'],
            ];

            // Adicionar endereço de cobrança se disponível
            if (!empty($data['card']['billing_address'])) {
                $creditCard['card']['billing_address'] = $data['card']['billing_address'];
            }
        }

        // Adicionar autenticação 3D Secure se solicitado
        if (!empty($data['use_3ds']) && $data['use_3ds'] === true) {
            $creditCard['authentication'] = $this->build3DSecurePayload($data);
        }

        return $creditCard;
    }

    /**
     * Constrói payload de autenticação 3D Secure
     */
    private function build3DSecurePayload(array $data): array
    {
        // Se dados 3DS externos foram fornecidos (third_party MPI)
        if (!empty($data['threed_secure'])) {
            return [
                'type' => 'threed_secure',
                'threed_secure' => [
                    'mpi' => 'third_party',
                    'eci' => $data['threed_secure']['eci'],
                    'cavv' => $data['threed_secure']['cavv'],
                    'ds_transaction_id' => $data['threed_secure']['ds_transaction_id'] ?? null,
                    'transaction_id' => $data['threed_secure']['transaction_id'] ?? null,
                    'version' => $data['threed_secure']['version'] ?? '2',
                ]
            ];
        }

        // Usar MPI do Pagar.me (recomendado)
        return [
            'type' => 'threed_secure',
            'threed_secure' => [
                'mpi' => 'pagarme'
            ]
        ];
    }

    /**
     * Tokeniza um cartão de crédito
     * 
     * @param array $cardData Dados do cartão
     * @return array|null
     */
    public function tokenizeCard(array $cardData): ?array
    {
        try {
            // Usar chave pública para tokenização
            $auth = base64_encode($this->publicKey . ':');
            
            $payload = [
                'type' => 'card',
                'card' => [
                    'number' => preg_replace('/\D/', '', $cardData['number']),
                    'holder_name' => strtoupper($cardData['holder_name']),
                    'exp_month' => (int) $cardData['exp_month'],
                    'exp_year' => (int) $cardData['exp_year'],
                    'cvv' => $cardData['cvv'],
                ]
            ];

            if (!empty($cardData['billing_address'])) {
                $payload['card']['billing_address'] = $cardData['billing_address'];
            }

            Log::info('PagarMeService::tokenizeCard - Tokenizando cartão');

            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(30)->post($this->baseUrl . 'tokens', $payload);

            if ($response->successful()) {
                $result = $response->json();
                Log::info('PagarMeService::tokenizeCard - Cartão tokenizado com sucesso:', [
                    'token_id' => $result['id'] ?? 'N/A'
                ]);
                return $result;
            }

            return $this->handleError($response);

        } catch (\Exception $e) {
            Log::error('PagarMeService::tokenizeCard - Exceção:', [
                'message' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Cria ou obtém um cliente na Pagar.me
     * 
     * @param array $data Dados do cliente
     * @return array|null
     */
    public function createCustomer(array $data): ?array
    {
        try {
            $payload = [
                'name' => $data['name'],
                'email' => $data['email'],
                'type' => $this->getCustomerType($data['document']),
                'document' => preg_replace('/\D/', '', $data['document']),
                'document_type' => strlen(preg_replace('/\D/', '', $data['document'])) === 14 ? 'CNPJ' : 'CPF',
            ];

            if (!empty($data['phone'])) {
                $phone = $this->parsePhone($data['phone']);
                $payload['phones'] = [
                    'mobile_phone' => [
                        'country_code' => '55',
                        'area_code' => $phone['area_code'],
                        'number' => $phone['number']
                    ]
                ];
            }

            Log::info('PagarMeService::createCustomer - Criando cliente');

            $response = Http::withHeaders($this->getAuthHeaders())
                ->timeout(30)
                ->post($this->baseUrl . 'customers', $payload);

            if ($response->successful()) {
                return $response->json();
            }

            return $this->handleError($response);

        } catch (\Exception $e) {
            Log::error('PagarMeService::createCustomer - Exceção:', [
                'message' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Adiciona um cartão a um cliente existente
     * 
     * @param string $customerId ID do cliente na Pagar.me
     * @param array $cardData Dados do cartão ou token
     * @return array|null
     */
    public function addCardToCustomer(string $customerId, array $cardData): ?array
    {
        try {
            $payload = [];

            if (!empty($cardData['card_token'])) {
                $payload['token'] = $cardData['card_token'];
            } else {
                $payload['number'] = preg_replace('/\D/', '', $cardData['number']);
                $payload['holder_name'] = strtoupper($cardData['holder_name']);
                $payload['exp_month'] = (int) $cardData['exp_month'];
                $payload['exp_year'] = (int) $cardData['exp_year'];
                $payload['cvv'] = $cardData['cvv'];

                if (!empty($cardData['billing_address'])) {
                    $payload['billing_address'] = $cardData['billing_address'];
                }
            }

            Log::info('PagarMeService::addCardToCustomer - Adicionando cartão ao cliente:', [
                'customer_id' => $customerId
            ]);

            $response = Http::withHeaders($this->getAuthHeaders())
                ->timeout(30)
                ->post($this->baseUrl . "customers/{$customerId}/cards", $payload);

            if ($response->successful()) {
                return $response->json();
            }

            return $this->handleError($response);

        } catch (\Exception $e) {
            Log::error('PagarMeService::addCardToCustomer - Exceção:', [
                'message' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Lista cartões de um cliente
     * 
     * @param string $customerId ID do cliente na Pagar.me
     * @return array|null
     */
    public function listCustomerCards(string $customerId): ?array
    {
        try {
            $response = Http::withHeaders($this->getAuthHeaders())
                ->timeout(30)
                ->get($this->baseUrl . "customers/{$customerId}/cards");

            if ($response->successful()) {
                return $response->json();
            }

            return $this->handleError($response);

        } catch (\Exception $e) {
            Log::error('PagarMeService::listCustomerCards - Exceção:', [
                'message' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Remove um cartão de um cliente
     * 
     * @param string $customerId ID do cliente
     * @param string $cardId ID do cartão
     * @return bool
     */
    public function deleteCustomerCard(string $customerId, string $cardId): bool
    {
        try {
            $response = Http::withHeaders($this->getAuthHeaders())
                ->timeout(30)
                ->delete($this->baseUrl . "customers/{$customerId}/cards/{$cardId}");

            return $response->successful();

        } catch (\Exception $e) {
            Log::error('PagarMeService::deleteCustomerCard - Exceção:', [
                'message' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Obtém detalhes de um pedido
     * 
     * @param string $orderId ID do pedido
     * @return array|null
     */
    public function getOrder(string $orderId): ?array
    {
        try {
            $response = Http::withHeaders($this->getAuthHeaders())
                ->timeout(30)
                ->get($this->baseUrl . "orders/{$orderId}");

            if ($response->successful()) {
                return $response->json();
            }

            return $this->handleError($response);

        } catch (\Exception $e) {
            Log::error('PagarMeService::getOrder - Exceção:', [
                'message' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Obtém detalhes de uma cobrança
     * 
     * @param string $chargeId ID da cobrança
     * @return array|null
     */
    public function getCharge(string $chargeId): ?array
    {
        try {
            $response = Http::withHeaders($this->getAuthHeaders())
                ->timeout(30)
                ->get($this->baseUrl . "charges/{$chargeId}");

            if ($response->successful()) {
                return $response->json();
            }

            return $this->handleError($response);

        } catch (\Exception $e) {
            Log::error('PagarMeService::getCharge - Exceção:', [
                'message' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Cancela uma cobrança (estorno)
     * 
     * @param string $chargeId ID da cobrança
     * @param int|null $amount Valor a estornar em centavos (null = estorno total)
     * @return array|null
     */
    public function cancelCharge(string $chargeId, ?int $amount = null): ?array
    {
        try {
            $payload = [];
            if ($amount !== null) {
                $payload['amount'] = $amount;
            }

            Log::info('PagarMeService::cancelCharge - Cancelando cobrança:', [
                'charge_id' => $chargeId,
                'amount' => $amount
            ]);

            $response = Http::withHeaders($this->getAuthHeaders())
                ->timeout(30)
                ->delete($this->baseUrl . "charges/{$chargeId}", $payload);

            if ($response->successful()) {
                return $response->json();
            }

            return $this->handleError($response);

        } catch (\Exception $e) {
            Log::error('PagarMeService::cancelCharge - Exceção:', [
                'message' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Captura uma cobrança pré-autorizada
     * 
     * @param string $chargeId ID da cobrança
     * @param int|null $amount Valor a capturar em centavos (null = captura total)
     * @return array|null
     */
    public function captureCharge(string $chargeId, ?int $amount = null): ?array
    {
        try {
            $payload = [];
            if ($amount !== null) {
                $payload['amount'] = $amount;
            }

            Log::info('PagarMeService::captureCharge - Capturando cobrança:', [
                'charge_id' => $chargeId,
                'amount' => $amount
            ]);

            $response = Http::withHeaders($this->getAuthHeaders())
                ->timeout(30)
                ->post($this->baseUrl . "charges/{$chargeId}/capture", $payload);

            if ($response->successful()) {
                return $response->json();
            }

            return $this->handleError($response);

        } catch (\Exception $e) {
            Log::error('PagarMeService::captureCharge - Exceção:', [
                'message' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Valida assinatura do webhook
     * 
     * @param string $payload Corpo da requisição
     * @param string $signature Assinatura do header X-Pagarme-Signature
     * @return bool
     */
    public function validateWebhookSignature(string $payload, string $signature): bool
    {
        if (empty($this->webhookSecret)) {
            Log::warning('PagarMeService::validateWebhookSignature - Webhook secret não configurado');
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $this->webhookSecret);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Calcula taxas de cartão para um valor
     * 
     * @param float $amount Valor bruto
     * @return array
     */
    public function calculateCardFees(float $amount): array
    {
        $percentFee = $amount * ($this->cardTxPercent / 100);
        $totalFee = $percentFee + $this->cardTxFixed;
        $netAmount = $amount - $totalFee;

        return [
            'gross_amount' => $amount,
            'percent_fee' => round($percentFee, 2),
            'fixed_fee' => $this->cardTxFixed,
            'total_fee' => round($totalFee, 2),
            'net_amount' => round($netAmount, 2),
            'days_availability' => $this->cardDaysAvailability,
        ];
    }

    /**
     * Salva cartão tokenizado no banco local
     * 
     * @param int $userId ID do usuário
     * @param array $cardData Dados do cartão da Pagar.me
     * @return UserCard|null
     */
    public function saveUserCard(int $userId, array $cardData): ?UserCard
    {
        try {
            return UserCard::updateOrCreate(
                [
                    'user_id' => $userId,
                    'card_id' => $cardData['id'],
                ],
                [
                    'customer_id' => $cardData['customer']['id'] ?? null,
                    'brand' => $cardData['brand'] ?? null,
                    'first_six_digits' => $cardData['first_six_digits'] ?? null,
                    'last_four_digits' => $cardData['last_four_digits'] ?? null,
                    'holder_name' => $cardData['holder_name'] ?? null,
                    'exp_month' => $cardData['exp_month'] ?? null,
                    'exp_year' => $cardData['exp_year'] ?? null,
                    'status' => $cardData['status'] ?? 'active',
                    'billing_address' => isset($cardData['billing_address']) 
                        ? json_encode($cardData['billing_address']) 
                        : null,
                ]
            );
        } catch (\Exception $e) {
            Log::error('PagarMeService::saveUserCard - Exceção:', [
                'message' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Determina o tipo de cliente baseado no documento
     */
    private function getCustomerType(string $document): string
    {
        $cleanDocument = preg_replace('/\D/', '', $document);
        return strlen($cleanDocument) === 14 ? 'company' : 'individual';
    }

    /**
     * Faz parse do telefone para formato Pagar.me
     */
    private function parsePhone(string $phone): array
    {
        $clean = preg_replace('/\D/', '', $phone);
        
        // Remover código do país se presente
        if (strlen($clean) > 11 && substr($clean, 0, 2) === '55') {
            $clean = substr($clean, 2);
        }

        return [
            'area_code' => substr($clean, 0, 2),
            'number' => substr($clean, 2),
        ];
    }

    /**
     * Remove dados sensíveis para log
     */
    private function sanitizeLogData(array $data): array
    {
        $sanitized = $data;
        
        // Mascarar dados sensíveis do cartão
        if (isset($sanitized['payments'])) {
            foreach ($sanitized['payments'] as &$payment) {
                if (isset($payment['credit_card']['card']['number'])) {
                    $payment['credit_card']['card']['number'] = '****' . substr($payment['credit_card']['card']['number'], -4);
                }
                if (isset($payment['credit_card']['card']['cvv'])) {
                    $payment['credit_card']['card']['cvv'] = '***';
                }
                if (isset($payment['credit_card']['card_token'])) {
                    $payment['credit_card']['card_token'] = substr($payment['credit_card']['card_token'], 0, 10) . '...';
                }
            }
        }

        // Mascarar documento do cliente
        if (isset($sanitized['customer']['document'])) {
            $doc = $sanitized['customer']['document'];
            $sanitized['customer']['document'] = substr($doc, 0, 3) . '***' . substr($doc, -2);
        }

        return $sanitized;
    }

    /**
     * Trata erros da API
     */
    private function handleError($response): array
    {
        $errorData = $response->json() ?? [];
        
        Log::error('PagarMeService - Erro na API:', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        $errorMessage = 'Erro desconhecido na API Pagar.me';

        if (isset($errorData['errors']) && is_array($errorData['errors'])) {
            $messages = array_map(function ($error) {
                return $error['message'] ?? $error['description'] ?? '';
            }, $errorData['errors']);
            $errorMessage = implode(', ', array_filter($messages));
        } elseif (isset($errorData['message'])) {
            $errorMessage = $errorData['message'];
        }

        return [
            'error' => true,
            'status_code' => $response->status(),
            'message' => $errorMessage,
            'details' => $errorData,
        ];
    }
}
