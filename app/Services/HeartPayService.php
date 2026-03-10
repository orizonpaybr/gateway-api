<?php

namespace App\Services;

use App\Models\HeartPay;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service para integração com HeartPay — Banking as a Service (PIX).
 *
 * Base URL:  https://app.heartpag.com/api/v1/client
 * Auth:      Authorization: Bearer {api_key}   (formato hpay_xxx)
 * Valores:   CENTAVOS (inteiros) — R$ 1,00 = 100
 * Fuso:      Brasília (UTC-3), datas em ISO 8601
 * Rate Limit: 10.000 req / 15 min por API Key
 */
class HeartPayService
{
    private HeartPay $config;
    private string $apiKey;
    private string $baseUrl;
    private int $maxRetries;
    private int $retryDelayMs;
    private int $timeout;
    private int $connectTimeout;

    public function __construct()
    {
        $this->config = HeartPay::first() ?? new HeartPay();

        $this->apiKey         = config('heartpay.api_key', '');
        $this->baseUrl        = rtrim(config('heartpay.api_url', 'https://app.heartpag.com/api/v1/client'), '/');
        $this->maxRetries     = (int) config('heartpay.max_retries', 3);
        $this->retryDelayMs   = (int) config('heartpay.retry_delay_ms', 1000);
        $this->timeout        = (int) config('heartpay.timeout', 30);
        $this->connectTimeout = (int) config('heartpay.connect_timeout', 10);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Verificação
    // ─────────────────────────────────────────────────────────────────

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    public function isActive(): bool
    {
        $status = $this->config->status ?? config('heartpay.status', false);
        $status = filter_var($status, FILTER_VALIDATE_BOOLEAN);
        return $status && $this->isConfigured();
    }

    public function reloadConfig(): void
    {
        $this->config         = HeartPay::first() ?? new HeartPay();
        $this->apiKey         = config('heartpay.api_key', '');
        $this->baseUrl        = rtrim(config('heartpay.api_url', 'https://app.heartpag.com/api/v1/client'), '/');
    }

    // ─────────────────────────────────────────────────────────────────
    //  Conversão de valores (centavos ↔ reais)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Reais → centavos (inteiro). Ex.: 10.50 → 1050
     */
    public static function toCents(float $reais): int
    {
        return (int) round($reais * 100);
    }

    /**
     * Centavos → reais (float). Ex.: 1050 → 10.50
     */
    public static function toReais(int $centavos): float
    {
        return round($centavos / 100, 2);
    }

    // ─────────────────────────────────────────────────────────────────
    //  HTTP Client base
    // ─────────────────────────────────────────────────────────────────

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ])
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->retry(
                $this->maxRetries,
                $this->retryDelayMs,
                fn ($exception, $request) => $this->shouldRetry($exception),
                throw: false
            );
    }

    private function shouldRetry(\Throwable $exception): bool
    {
        if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
            return true;
        }

        if ($exception instanceof \Illuminate\Http\Client\RequestException) {
            $status = $exception->response?->status();
            return $status !== null && $status >= 500;
        }

        return false;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Métodos HTTP genéricos
    // ─────────────────────────────────────────────────────────────────

    public function get(string $endpoint, array $query = []): array
    {
        $url = $this->resolveUrl($endpoint);

        Log::debug('[HeartPay] GET ' . $endpoint, ['query' => $query]);

        $response = $this->http()->get($url, $query);

        return $this->handleResponse($response, 'GET', $endpoint);
    }

    public function post(string $endpoint, array $data = []): array
    {
        $url = $this->resolveUrl($endpoint);

        Log::debug('[HeartPay] POST ' . $endpoint, [
            'body_keys' => array_keys($data),
        ]);

        $response = $this->http()->post($url, $data);

        return $this->handleResponse($response, 'POST', $endpoint);
    }

    public function put(string $endpoint, array $data = []): array
    {
        $url = $this->resolveUrl($endpoint);

        Log::debug('[HeartPay] PUT ' . $endpoint, [
            'body_keys' => array_keys($data),
        ]);

        $response = $this->http()->put($url, $data);

        return $this->handleResponse($response, 'PUT', $endpoint);
    }

    public function delete(string $endpoint): array
    {
        $url = $this->resolveUrl($endpoint);

        Log::debug('[HeartPay] DELETE ' . $endpoint);

        $response = $this->http()->delete($url);

        return $this->handleResponse($response, 'DELETE', $endpoint);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Resolução de URL e tratamento de resposta
    // ─────────────────────────────────────────────────────────────────

    private function resolveUrl(string $endpoint): string
    {
        $endpoint = ltrim($endpoint, '/');
        return $this->baseUrl . '/' . $endpoint;
    }

    private function handleResponse(Response $response, string $method, string $endpoint): array
    {
        $status = $response->status();
        $body   = $response->json() ?? [];

        $this->logRateLimitHeaders($response, $endpoint);

        if ($response->successful()) {
            return [
                'success'     => true,
                'status_code' => $status,
                'data'        => $body,
            ];
        }

        $errorCode    = $body['code'] ?? $body['error'] ?? 'UNKNOWN';
        $errorMessage = $body['message'] ?? 'Erro desconhecido';

        Log::error("[HeartPay] {$method} {$endpoint} falhou", [
            'status'  => $status,
            'code'    => $errorCode,
            'message' => $errorMessage,
            'body'    => $body,
        ]);

        if ($status === 429) {
            $retryAfter = $response->header('Retry-After') ?? $body['retryAfter'] ?? $response->header('RateLimit-Reset');
            Log::warning('[HeartPay] Rate limit atingido (429)', [
                'endpoint'    => $endpoint,
                'retryAfter'  => $retryAfter,
                'limit'       => $body['limit'] ?? $response->header('RateLimit-Limit'),
                'remaining'   => $response->header('RateLimit-Remaining'),
            ]);
        }

        return [
            'success'      => false,
            'status_code'  => $status,
            'error'        => $errorCode,
            'message'      => $errorMessage,
            'data'         => $body,
        ];
    }

    private function logRateLimitHeaders(Response $response, string $endpoint): void
    {
        $remaining = $response->header('RateLimit-Remaining');
        if ($remaining !== null && (int) $remaining < 500) {
            Log::warning('[HeartPay] Rate limit baixo', [
                'endpoint'  => $endpoint,
                'remaining' => $remaining,
                'limit'     => $response->header('RateLimit-Limit'),
                'reset'     => $response->header('RateLimit-Reset'),
            ]);
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  CHARGES (Cobranças PIX — Cash In)
    // ═════════════════════════════════════════════════════════════════

    /**
     * Cria uma cobrança PIX com QR Code.
     *
     * @param float  $amountReais  Valor em REAIS (será convertido para centavos)
     * @param array  $customer     ['name' => ..., 'taxID' => ..., 'email' => ?, 'phone' => ?]
     * @param string|null $correlationID  Min 26 chars alfanuméricos; se null, HeartPay gera
     * @param string|null $comment  Descrição que aparece no extrato do pagador
     * @param string|null $expiresDate  ISO 8601; default 24h
     */
    public function createCharge(
        float $amountReais,
        array $customer,
        ?string $correlationID = null,
        ?string $comment = null,
        ?string $expiresDate = null
    ): array {
        $body = [
            'value'    => self::toCents($amountReais),
            'customer' => $customer,
        ];

        if ($correlationID !== null) {
            $body['correlationID'] = $this->sanitizeCorrelationID($correlationID);
        }
        if ($comment !== null) {
            $body['comment'] = $comment;
        }
        if ($expiresDate !== null) {
            $body['expiresDate'] = $expiresDate;
        }

        $result = $this->post('charges', $body);

        if (!$result['success']) {
            return $result;
        }

        $data = $result['data']['data'] ?? $result['data'] ?? [];

        return [
            'success'         => true,
            'correlationID'   => $data['correlationID'] ?? $result['data']['correlationID'] ?? null,
            'brCode'          => $data['brCode'] ?? $result['data']['brCode'] ?? null,
            'qrCodeImage'     => $data['qrCodeImage'] ?? $result['data']['qrCodeImage'] ?? null,
            'paymentLinkUrl'  => $data['paymentLinkUrl'] ?? $result['data']['paymentLinkUrl'] ?? null,
            'status'          => $data['status'] ?? 'ACTIVE',
            'expiresDate'     => $data['expiresDate'] ?? $data['expiresAt'] ?? $result['data']['expiresDate'] ?? null,
            'value_cents'     => $data['value'] ?? self::toCents($amountReais),
            'deduplicated'    => $result['data']['deduplicated'] ?? false,
            'raw'             => $result['data'],
        ];
    }

    /**
     * Busca cobrança pelo correlationID.
     */
    public function getCharge(string $correlationID): array
    {
        $result = $this->get("charges/{$correlationID}");

        if (!$result['success']) {
            return $result;
        }

        $data = $result['data']['data'] ?? $result['data'] ?? [];

        return [
            'success' => true,
            'status'  => $data['status'] ?? 'UNKNOWN',
            'data'    => $data,
        ];
    }

    /**
     * Busca cobrança pelo End-to-End ID do Banco Central.
     */
    public function getChargeByE2E(string $endToEndId): array
    {
        return $this->get("charges/e2e/{$endToEndId}");
    }

    /**
     * Lista cobranças com filtros e paginação.
     */
    public function listCharges(array $filters = []): array
    {
        return $this->get('charges', $filters);
    }

    /**
     * Cancela uma cobrança ativa (apenas ACTIVE, apenas provedor Woovi).
     */
    public function cancelCharge(string $correlationID): array
    {
        return $this->delete("charges/{$correlationID}");
    }

    // ═════════════════════════════════════════════════════════════════
    //  PAYOUTS (Saques/Transferências PIX — Cash Out)
    // ═════════════════════════════════════════════════════════════════

    /**
     * Cria uma transferência PIX (Cash Out).
     *
     * @param float  $amountReais  Valor em REAIS
     * @param string $pixKey       Chave PIX do destinatário
     * @param string $pixKeyType   cpf | cnpj | email | phone | random
     * @param string|null $description  Descrição interna
     * @param string|null $correlationID  Para deduplicação
     */
    public function createPayout(
        float $amountReais,
        string $pixKey,
        string $pixKeyType,
        ?string $description = null,
        ?string $correlationID = null
    ): array {
        $body = [
            'value'      => self::toCents($amountReais),
            'pixKey'     => $pixKey,
            'pixKeyType' => $this->normalizePixKeyType($pixKeyType),
        ];

        if ($description !== null) {
            $body['description'] = $description;
        }
        if ($correlationID !== null) {
            $body['correlationID'] = $correlationID;
        }

        $result = $this->post('payouts', $body);

        if (!$result['success']) {
            return $result;
        }

        $data = $result['data']['data'] ?? $result['data'] ?? [];

        return [
            'success'       => true,
            'referenceCode' => $data['referenceCode'] ?? $data['reference_code'] ?? null,
            'status'        => $data['status'] ?? 'pending',
            'amount_cents'  => $data['amount'] ?? $data['value'] ?? self::toCents($amountReais),
            'raw'           => $result['data'],
        ];
    }

    /**
     * Busca status de um saque pelo referenceCode ou correlationID.
     */
    public function getPayout(string $identifier): array
    {
        $result = $this->get("payouts/{$identifier}");

        if (!$result['success']) {
            return $result;
        }

        $data = $result['data']['data'] ?? $result['data'] ?? [];

        return [
            'success'      => true,
            'status'       => $data['status'] ?? 'UNKNOWN',
            'error_message' => $data['error_message'] ?? $data['errorMessage'] ?? null,
            'data'         => $data,
        ];
    }

    /**
     * Lista todos os saques com filtros.
     */
    public function listPayouts(array $filters = []): array
    {
        return $this->get('payouts', $filters);
    }

    /**
     * Gera comprovante de saque em PNG (base64). Apenas COMPLETED ou FAILED.
     */
    public function getPayoutReceipt(string $correlationID): array
    {
        return $this->get("payouts/{$correlationID}/receipt");
    }

    // ═════════════════════════════════════════════════════════════════
    //  REFUNDS (Reembolsos)
    // ═════════════════════════════════════════════════════════════════

    /**
     * Cria reembolso total ou parcial de uma cobrança COMPLETED.
     *
     * @param string   $correlationID  correlationID da cobrança original
     * @param float|null $amountReais  Valor em REAIS para reembolso parcial; null = total
     * @param string|null $comment     Motivo do reembolso
     */
    public function createRefund(
        string $correlationID,
        ?float $amountReais = null,
        ?string $comment = null
    ): array {
        $body = [
            'correlationID' => $correlationID,
        ];

        if ($amountReais !== null) {
            $body['value'] = self::toCents($amountReais);
        }
        if ($comment !== null) {
            $body['comment'] = $comment;
        }

        $result = $this->post('refunds', $body);

        if (!$result['success']) {
            return $result;
        }

        $data = $result['data']['data'] ?? $result['data'] ?? [];

        return [
            'success'        => true,
            'refundedAmount' => $data['refundedAmount'] ?? $data['value'] ?? null,
            'originalAmount' => $data['originalAmount'] ?? null,
            'refundedAt'     => $data['refundedAt'] ?? null,
            'raw'            => $result['data'],
        ];
    }

    // ═════════════════════════════════════════════════════════════════
    //  BALANCE (Saldo)
    // ═════════════════════════════════════════════════════════════════

    /**
     * Consulta saldo da conta HeartPay. Valores em centavos.
     */
    public function getBalance(): array
    {
        $result = $this->get('balance');

        if (!$result['success']) {
            return $result;
        }

        $data = $result['data']['data'] ?? $result['data'] ?? [];

        return [
            'success'               => true,
            'availableBalance'      => $data['availableBalance'] ?? 0,
            'totalReceived'         => $data['totalReceived'] ?? 0,
            'totalFees'             => $data['totalFees'] ?? 0,
            'totalPayouts'          => $data['totalPayouts'] ?? 0,
            'totalPendingPayouts'   => $data['totalPendingPayouts'] ?? 0,
            'totalBlocked'          => $data['totalBlocked'] ?? 0,
            'withdrawalsBlocked'    => $data['withdrawalsBlocked'] ?? false,
            'raw'                   => $data,
        ];
    }

    // ═════════════════════════════════════════════════════════════════
    //  TRANSACTIONS (Transações — entradas e saídas)
    // ═════════════════════════════════════════════════════════════════

    /**
     * Lista transações (CREDIT/DEBIT) com filtros e paginação.
     */
    public function listTransactions(array $filters = []): array
    {
        return $this->get('transactions', $filters);
    }

    // ═════════════════════════════════════════════════════════════════
    //  CUSTOMERS (Clientes — opcional)
    // ═════════════════════════════════════════════════════════════════

    public function createCustomer(array $data): array
    {
        return $this->post('customers', $data);
    }

    public function listCustomers(array $filters = []): array
    {
        return $this->get('customers', $filters);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Mapeamento de status HeartPay → Orizon
    // ═════════════════════════════════════════════════════════════════

    /**
     * Mapeia status de cobrança (charge) HeartPay → status interno Orizon.
     */
    public static function mapChargeStatus(string $heartPayStatus): string
    {
        return match (strtoupper(trim($heartPayStatus))) {
            'ACTIVE'    => 'WAITING_FOR_APPROVAL',
            'COMPLETED' => 'PAID_OUT',
            'EXPIRED'   => 'CANCELLED',
            'REFUNDED'  => 'REFUNDED',
            default     => 'PENDING',
        };
    }

    /**
     * Mapeia status de saque (payout) HeartPay → status interno Orizon.
     */
    public static function mapPayoutStatus(string $heartPayStatus): string
    {
        return match (strtolower(trim($heartPayStatus))) {
            'pending', 'pending_approval' => 'PENDING',
            'processing'                  => 'PROCESSING',
            'approved', 'completed'       => 'PAID_OUT',
            'rejected'                    => 'CANCELLED',
            'failed'                      => 'FAILED',
            default                       => 'PENDING',
        };
    }

    // ═════════════════════════════════════════════════════════════════
    //  Helpers internos
    // ═════════════════════════════════════════════════════════════════

    /**
     * Sanitiza correlationID: apenas alfanuméricos, mín 26 chars.
     */
    private function sanitizeCorrelationID(string $id): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9]/', '', $id);

        if (strlen($clean) < 26) {
            $clean .= str()->random(26 - strlen($clean));
        }

        return $clean;
    }

    /**
     * Normaliza pixKeyType para o formato aceito pela HeartPay.
     */
    private function normalizePixKeyType(string $type): string
    {
        return match (strtolower(trim($type))) {
            'cpf'                        => 'cpf',
            'cnpj'                       => 'cnpj',
            'email'                      => 'email',
            'telefone', 'phone', 'cel'   => 'phone',
            'aleatoria', 'random', 'evp' => 'random',
            default                      => $type,
        };
    }

    // ─────────────────────────────────────────────────────────────────
    //  Getters
    // ─────────────────────────────────────────────────────────────────

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getConfig(): HeartPay
    {
        return $this->config;
    }
}
