<?php

namespace App\Services\FluxPayments;

use App\Helpers\SecureHttp;
use App\Services\PixAcquirer\PixAcquirerInterface;
use Illuminate\Support\Facades\Log;

class FluxPaymentsPixAcquirerService implements PixAcquirerInterface
{
    private FluxPaymentsAuthService $auth;

    private string $baseUrl;

    private int $timeout;

    public function __construct(FluxPaymentsAuthService $auth)
    {
        $this->auth = $auth;
        $this->baseUrl = rtrim((string) config('fluxpayments.base_url'), '/');
        $this->timeout = (int) config('fluxpayments.timeout', 30);
    }

    public function getReference(): string
    {
        return 'fluxpayments';
    }

    public function isActive(): bool
    {
        return $this->auth->isConfigured();
    }

    public function createCharge(
        float $amountReais,
        array $customer,
        ?string $correlationId = null,
        ?string $comment = null,
        ?string $expiresDate = null
    ): array {
        $amountCents = $this->toCents($amountReais);
        if ($amountCents < 100) {
            return [
                'success' => false,
                'message' => 'Valor mínimo para PIX FluxPayments é R$ 1,00.',
            ];
        }

        $document = preg_replace('/\D/', '', (string) ($customer['document'] ?? ''));
        $name = trim((string) ($customer['name'] ?? ''));
        $email = trim((string) ($customer['email'] ?? ''));
        $phone = preg_replace('/\D/', '', (string) ($customer['phone'] ?? ''));

        if ($name === '' || $document === '') {
            return [
                'success' => false,
                'message' => 'Nome e documento do cliente são obrigatórios para FluxPayments.',
            ];
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = (string) config('fluxpayments.fallback_email', 'noreply@coratri.com.br');
        }

        if ($phone === '') {
            $phone = preg_replace('/\D/', '', (string) config('fluxpayments.fallback_phone', '11999999999'));
        }

        $itemTitle = trim((string) ($comment ?? ''));
        if ($itemTitle === '') {
            $itemTitle = 'Depósito PIX';
        }

        $payload = [
            'amount' => $amountCents,
            'paymentMethod' => 'pix',
            'customer' => [
                'name' => mb_substr($name, 0, 120),
                'email' => $email,
                'phone' => $phone,
                'document' => [
                    'number' => $document,
                    'type' => strlen($document) > 11 ? 'cnpj' : 'cpf',
                ],
            ],
            'items' => [
                [
                    'title' => mb_substr($itemTitle, 0, 140),
                    'unitPrice' => $amountCents,
                    'quantity' => 1,
                    'tangible' => false,
                ],
            ],
            'pix' => $this->buildPixExpiration(),
            'postbackUrl' => $this->resolveWebhookUrl(),
        ];

        $webhookSecret = trim((string) config('fluxpayments.webhook_secret', ''));
        if ($webhookSecret !== '') {
            $payload['webhookSecret'] = $webhookSecret;
        }

        if ($correlationId !== null && $correlationId !== '') {
            $payload['externalRef'] = $correlationId;
        }

        $url = $this->baseUrl.'/api/v1/transactions';

        try {
            $response = SecureHttp::post($url, $payload, $this->auth->authHeaders(), $this->timeout);
            $body = is_array($response->json()) ? $response->json() : [];

            if (! $response->successful()) {
                $errorMsg = $this->extractErrorMessage($body, 'Erro ao gerar cobrança PIX na FluxPayments');

                Log::error('[FLUXPAYMENTS][CHARGE] Falha ao gerar cash in', [
                    'status' => $response->status(),
                    'error' => $errorMsg,
                    'amount' => $amountReais,
                    'correlation_id' => $correlationId,
                    'details' => $body['details'] ?? null,
                ]);

                return [
                    'success' => false,
                    'message' => $errorMsg,
                    'raw' => $body,
                ];
            }

            $transactionId = (string) ($body['id'] ?? '');
            $brCode = $body['pix']['qrcode'] ?? null;

            if ($transactionId === '' || empty($brCode)) {
                Log::error('[FLUXPAYMENTS][CHARGE] Resposta sem id/qrcode', [
                    'body_keys' => array_keys($body),
                ]);

                return [
                    'success' => false,
                    'message' => 'Resposta inválida da FluxPayments (sem id ou QR Code).',
                    'raw' => $body,
                ];
            }

            Log::info('[FLUXPAYMENTS][CHARGE] Cash in gerado', [
                'transaction_id' => $transactionId,
                'amount' => $amountReais,
                'correlation_id' => $correlationId,
                'status' => $body['status'] ?? 'pending',
            ]);

            return [
                'success' => true,
                'brCode' => $brCode,
                'correlationID' => $transactionId,
                'expiresDate' => $body['pix']['expiresAt'] ?? null,
                'status' => strtolower((string) ($body['status'] ?? 'pending')),
                'raw' => [
                    'id' => $transactionId,
                    'externalRef' => $body['externalRef'] ?? $correlationId,
                    'expiresAt' => $body['pix']['expiresAt'] ?? null,
                    'fee' => $body['fee'] ?? null,
                    'companyId' => $body['companyId'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('[FLUXPAYMENTS][CHARGE] Exceção ao gerar cash in', [
                'error' => $e->getMessage(),
                'amount' => $amountReais,
                'correlation_id' => $correlationId,
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com FluxPayments: '.$e->getMessage(),
            ];
        }
    }

    public function createPayout(
        float $amountReais,
        string $pixKey,
        string $pixKeyType,
        ?string $description = null,
        ?string $correlationId = null,
        ?string $recipientName = null,
        ?string $recipientDocument = null
    ): array {
        $amountCents = $this->toCents($amountReais);
        if ($amountCents < 1) {
            return [
                'success' => false,
                'message' => 'Valor inválido para PIX OUT FluxPayments.',
            ];
        }

        $mappedKeyType = $this->mapPixKeyType($pixKeyType);
        $formattedKey = $this->formatPixKey($pixKey, $mappedKeyType);
        $destinationName = trim((string) ($recipientName ?? ''));
        $destinationDocument = preg_replace('/\D/', '', (string) ($recipientDocument ?? ''));

        if ($destinationDocument === '' && in_array($mappedKeyType, ['CPF', 'CNPJ'], true)) {
            $destinationDocument = preg_replace('/\D/', '', $formattedKey);
        }

        if ($destinationName === '' || $destinationDocument === '') {
            return [
                'success' => false,
                'message' => 'Nome e CPF/CNPJ do destinatário são obrigatórios para PIX OUT FluxPayments.',
            ];
        }

        if ($formattedKey === '') {
            return [
                'success' => false,
                'message' => 'Chave PIX inválida para FluxPayments.',
            ];
        }

        $idempotencyKey = $correlationId !== null && trim($correlationId) !== ''
            ? trim($correlationId)
            : preg_replace('/[^a-zA-Z0-9]/', '', (string) \Illuminate\Support\Str::uuid());

        $payload = [
            'amount' => $amountCents,
            'pixKey' => $formattedKey,
            'pixKeyType' => $mappedKeyType,
            'destinationName' => mb_substr($destinationName, 0, 120),
            'destinationDocument' => $destinationDocument,
            'postbackUrl' => $this->resolveWebhookUrl(),
        ];

        $webhookSecret = trim((string) config('fluxpayments.webhook_secret', ''));
        if ($webhookSecret !== '') {
            $payload['webhookSecret'] = $webhookSecret;
        }

        if ($correlationId !== null && trim($correlationId) !== '') {
            $payload['externalRef'] = trim($correlationId);
        }

        if ($description !== null && trim($description) !== '') {
            $payload['metadata'] = [
                'description' => mb_substr(trim($description), 0, 140),
            ];
        }

        $headers = array_merge($this->auth->authHeaders(), [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $url = $this->baseUrl.'/api/v1/transactions/pix-out';

        try {
            $response = SecureHttp::post($url, $payload, $headers, $this->timeout);
            $body = is_array($response->json()) ? $response->json() : [];

            if (! $response->successful() || empty($body['success'])) {
                $errorMsg = $this->extractErrorMessage($body, 'Erro ao processar PIX OUT na FluxPayments');

                Log::error('[FLUXPAYMENTS][PAYOUT] Falha no cash out', [
                    'status' => $response->status(),
                    'error' => $errorMsg,
                    'code' => $body['code'] ?? null,
                    'amount' => $amountReais,
                    'pix_key_type' => $mappedKeyType,
                    'correlation_id' => $correlationId,
                ]);

                return [
                    'success' => false,
                    'message' => $errorMsg,
                    'raw' => $body,
                ];
            }

            $data = is_array($body['data'] ?? null) ? $body['data'] : [];
            $transactionId = (string) ($data['id'] ?? '');
            $providerStatus = strtoupper((string) ($data['status'] ?? 'PROCESSING'));

            if ($transactionId === '') {
                return [
                    'success' => false,
                    'message' => 'Resposta inválida da FluxPayments (sem id da transferência).',
                    'raw' => $body,
                ];
            }

            Log::info('[FLUXPAYMENTS][PAYOUT] Cash out aceito', [
                'transaction_id' => $transactionId,
                'status' => $providerStatus,
                'amount' => $amountReais,
                'correlation_id' => $correlationId,
                'idempotency_key' => $idempotencyKey,
            ]);

            return [
                'success' => true,
                'referenceCode' => $transactionId,
                'status' => $providerStatus,
                'raw' => [
                    'id' => $transactionId,
                    'secureId' => $data['secureId'] ?? null,
                    'endToEndId' => $data['endToEndId'] ?? $data['end_to_end_id'] ?? null,
                    'fee' => $data['fee'] ?? null,
                    'netAmount' => $data['netAmount'] ?? null,
                    'requiresManualApproval' => $data['requiresManualApproval'] ?? false,
                    'idempotencyKey' => $idempotencyKey,
                    'externalRef' => $correlationId,
                    'provider_status' => $providerStatus,
                    'destinationName' => $data['destinationName'] ?? $destinationName,
                    'destinationDocument' => $data['destinationDocument'] ?? $destinationDocument,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('[FLUXPAYMENTS][PAYOUT] Exceção ao processar cash out', [
                'error' => $e->getMessage(),
                'amount' => $amountReais,
                'pix_key_type' => $mappedKeyType,
                'correlation_id' => $correlationId,
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com FluxPayments: '.$e->getMessage(),
            ];
        }
    }

    public function mapPayoutStatus(string $providerStatus): string
    {
        return match (strtoupper(trim($providerStatus))) {
            'SUCCESS', 'COMPLETED', 'PAID', 'PAID_OUT', 'APPROVED', 'DONE' => 'COMPLETED',
            'PROCESSING', 'PENDING', 'QUEUED', 'WAITING', 'WAITING_APPROVAL' => 'PROCESSING',
            'CANCELED', 'CANCELLED', 'EXPIRED' => 'CANCELLED',
            'ERROR', 'FAILED', 'REFUSED', 'REJECTED' => 'FAILED',
            'REFUNDED', 'CHARGEBACK', 'PARTIALLY_REFUNDED' => 'REFUNDED',
            default => 'PROCESSING',
        };
    }

    public function getPayoutStatus(string $transactionId, ?string $e2eId = null): array
    {
        $tid = trim($transactionId);
        $e2e = $e2eId !== null ? trim($e2eId) : '';

        if ($tid === '' && $e2e === '') {
            return [
                'success' => false,
                'message' => 'Informe id ou e2e da transferência FluxPayments.',
            ];
        }

        $query = [];
        if ($tid !== '') {
            $query['id'] = $tid;
        }
        if ($e2e !== '') {
            $query['e2e'] = $e2e;
        }

        $url = $this->baseUrl.'/api/v1/transactions/pix-out';

        try {
            $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout, $query);
            $body = is_array($response->json()) ? $response->json() : [];

            if (! $response->successful()) {
                $errorMsg = $this->extractErrorMessage($body, 'Transferência não encontrada');

                Log::warning('[FLUXPAYMENTS][PAYOUT_STATUS] Falha ao consultar status', [
                    'transaction_id' => $tid !== '' ? $tid : null,
                    'e2e' => $e2e !== '' ? $e2e : null,
                    'http_status' => $response->status(),
                    'error' => $errorMsg,
                ]);

                return [
                    'success' => false,
                    'message' => $errorMsg,
                    'http_status' => $response->status(),
                ];
            }

            $providerStatus = strtoupper((string) ($body['status'] ?? ''));
            if ($providerStatus === '' && ! empty($body['paidAt'])) {
                $providerStatus = 'PAID';
            }

            $pix = is_array($body['pix'] ?? null) ? $body['pix'] : [];
            $endToEndId = $body['pixEnd2EndId']
                ?? $body['pixEndToEndId']
                ?? $pix['end2EndId']
                ?? $pix['endToEndId']
                ?? $pix['end_to_end_id']
                ?? null;
            $destination = is_array($pix['destination'] ?? null) ? $pix['destination'] : [];
            $beneficiary = is_array($pix['beneficiary'] ?? null) ? $pix['beneficiary'] : [];

            return [
                'success' => true,
                'status' => $this->mapPayoutStatus($providerStatus),
                'raw' => [
                    'id' => $body['id'] ?? null,
                    'externalRef' => $body['externalRef'] ?? null,
                    'endToEndId' => $endToEndId,
                    'paidAt' => $body['paidAt'] ?? null,
                    'cancelledAt' => $body['cancelledAt'] ?? null,
                    'refusedReason' => $body['refusedReason'] ?? null,
                    'refusedCode' => $body['refusedCode'] ?? null,
                    'fee' => $body['fee'] ?? null,
                    'provider_status' => $providerStatus,
                    'destinationName' => $destination['name'] ?? $beneficiary['name'] ?? null,
                    'destinationDocument' => $destination['document'] ?? $beneficiary['document'] ?? null,
                    'pixKey' => $destination['pixKey'] ?? null,
                    'pixKeyType' => $destination['pixKeyType'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('[FLUXPAYMENTS][PAYOUT_STATUS] Exceção ao consultar status', [
                'transaction_id' => $tid !== '' ? $tid : null,
                'e2e' => $e2e !== '' ? $e2e : null,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com FluxPayments: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Consulta saldos das carteiras FluxPayments (GET /api/v1/balance).
     * Não é usado no fluxo de saque — o gateway valida apenas o saldo interno Coratri.
     *
     * @return array{success: bool, message?: string, data?: array, raw?: array}
     */
    public function getBalances(): array
    {
        $url = $this->baseUrl.'/api/v1/balance';

        try {
            $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout);
            $body = is_array($response->json()) ? $response->json() : [];

            if (! $response->successful() || empty($body['success'])) {
                return [
                    'success' => false,
                    'message' => $this->extractErrorMessage($body, 'Falha ao consultar saldo FluxPayments'),
                    'raw' => $body,
                ];
            }

            return [
                'success' => true,
                'data' => is_array($body['data'] ?? null) ? $body['data'] : [],
                'raw' => $body,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Erro ao conectar com FluxPayments: '.$e->getMessage(),
            ];
        }
    }

    private function mapPixKeyType(string $pixKeyType): string
    {
        return match (strtolower(trim($pixKeyType))) {
            'cpf' => 'CPF',
            'cnpj' => 'CNPJ',
            'email' => 'EMAIL',
            'telefone', 'phone', 'celular' => 'PHONE',
            'aleatoria', 'random', 'evp' => 'RANDOM',
            default => strtoupper(trim($pixKeyType)),
        };
    }

    private function formatPixKey(string $pixKey, string $mappedKeyType): string
    {
        $key = trim($pixKey);

        return match ($mappedKeyType) {
            'CPF', 'CNPJ', 'PHONE' => preg_replace('/\D/', '', $key) ?? '',
            'EMAIL' => strtolower($key),
            'RANDOM' => strtolower($key),
            default => $key,
        };
    }

    public function getChargeStatus(string $transactionId): array
    {
        $tid = trim($transactionId);
        if ($tid === '') {
            return [
                'success' => false,
                'message' => 'Informe o id da transação FluxPayments.',
            ];
        }

        $url = $this->baseUrl.'/api/v1/transactions/pix-in';

        try {
            $response = SecureHttp::get(
                $url,
                $this->auth->authHeaders(),
                $this->timeout,
                ['id' => $tid]
            );
            $body = is_array($response->json()) ? $response->json() : [];

            if (! $response->successful()) {
                $errorMsg = $this->extractErrorMessage($body, 'Cobrança não encontrada');

                Log::warning('[FLUXPAYMENTS][CHARGE_STATUS] Falha ao consultar status', [
                    'transaction_id' => $tid,
                    'http_status' => $response->status(),
                    'error' => $errorMsg,
                ]);

                return [
                    'success' => false,
                    'message' => $errorMsg,
                    'http_status' => $response->status(),
                ];
            }

            $providerStatus = strtoupper((string) ($body['status'] ?? ''));
            $mappedStatus = $this->mapChargeStatus($providerStatus, $body);

            $pix = is_array($body['pix'] ?? null) ? $body['pix'] : [];
            $endToEndId = $pix['end2EndId']
                ?? $pix['endToEndId']
                ?? $pix['end_to_end_id']
                ?? $body['pixEnd2EndId']
                ?? null;

            return [
                'success' => true,
                'status' => $mappedStatus,
                'raw' => [
                    'id' => $body['id'] ?? null,
                    'externalRef' => $body['externalRef'] ?? null,
                    'endToEndId' => $endToEndId,
                    'paidAt' => $body['paidAt'] ?? null,
                    'amount' => $body['amount'] ?? null,
                    'refundedAmount' => $body['refundedAmount'] ?? null,
                    'fee' => $body['fee'] ?? null,
                    'provider_status' => $providerStatus,
                    'qrcode' => $pix['qrcode'] ?? null,
                    'expiresAt' => $pix['expiresAt'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('[FLUXPAYMENTS][CHARGE_STATUS] Exceção ao consultar status', [
                'transaction_id' => $tid,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com FluxPayments: '.$e->getMessage(),
            ];
        }
    }

    public function createRefund(string $transactionId, float $amount, string $reason): array
    {
        $tid = trim($transactionId);
        if ($tid === '') {
            return [
                'success' => false,
                'message' => 'Informe o id da transação FluxPayments para estorno.',
            ];
        }

        $amountCents = $this->toCents($amount);
        $payload = [
            'reason' => $reason !== '' ? $reason : 'Estorno solicitado',
        ];

        // Mínimo documentado para estorno parcial: 100 centavos
        if ($amountCents >= 100) {
            $payload['amount'] = $amountCents;
        }

        $url = $this->baseUrl.'/api/v1/transactions/'.rawurlencode($tid).'/refund';

        try {
            $response = SecureHttp::delete($url, $payload, $this->auth->authHeaders(), $this->timeout);
            $body = is_array($response->json()) ? $response->json() : [];

            if (! $response->successful() || empty($body['success'])) {
                $errorMsg = $this->extractErrorMessage($body, 'Erro ao processar estorno na FluxPayments');

                Log::error('[FLUXPAYMENTS][REFUND] Falha ao solicitar estorno', [
                    'status' => $response->status(),
                    'error' => $errorMsg,
                    'transaction_id' => $tid,
                    'amount' => $amount,
                    'code' => $body['code'] ?? null,
                ]);

                return [
                    'success' => false,
                    'message' => $errorMsg,
                    'raw' => $body,
                ];
            }

            $refundAmount = $body['refundAmount'] ?? $amountCents;
            $providerStatus = strtoupper((string) ($body['status'] ?? 'REFUNDED'));

            Log::info('[FLUXPAYMENTS][REFUND] Estorno solicitado', [
                'transaction_id' => $tid,
                'refund_amount' => $refundAmount,
                'status' => $providerStatus,
            ]);

            $pixReversal = is_array($body['pixReversal'] ?? null) ? $body['pixReversal'] : [];

            return [
                'success' => true,
                'refundId' => (string) ($pixReversal['endToEndId'] ?? $body['transactionId'] ?? $tid),
                'status' => $providerStatus,
                'raw' => [
                    'transactionId' => $body['transactionId'] ?? $tid,
                    'refundAmount' => $refundAmount,
                    'totalRefunded' => $body['totalRefunded'] ?? null,
                    'pixReversal' => $pixReversal,
                    'provider_status' => $providerStatus,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('[FLUXPAYMENTS][REFUND] Exceção ao solicitar estorno', [
                'transaction_id' => $tid,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com FluxPayments: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function mapChargeStatus(string $providerStatus, array $body = []): string
    {
        $status = strtoupper(trim($providerStatus));

        if ($status === '' && ! empty($body['paidAt'])) {
            return 'PAID_OUT';
        }

        return match ($status) {
            'PAID', 'PAID_OUT', 'APPROVED', 'COMPLETED', 'CONCLUDED' => 'PAID_OUT',
            'REFUNDED', 'CHARGEBACK', 'PARTIALLY_REFUNDED' => 'REFUNDED',
            'CANCELED', 'CANCELLED', 'EXPIRED', 'REFUSED' => 'CANCELED',
            'FAILED', 'ERROR' => 'FAILED',
            'PENDING', 'WAITING', 'WAITING_PAYMENT', 'CREATED' => 'WAITING_FOR_APPROVAL',
            default => ! empty($body['paidAt']) ? 'PAID_OUT' : 'WAITING_FOR_APPROVAL',
        };
    }

    /**
     * @return array{expiresInSeconds?: int, expiresInDays?: int}
     */
    private function buildPixExpiration(): array
    {
        $seconds = config('fluxpayments.expires_in_seconds');
        if ($seconds !== null && (int) $seconds > 0) {
            return ['expiresInSeconds' => (int) $seconds];
        }

        $days = (int) config('fluxpayments.expires_in_days', 1);

        return ['expiresInDays' => max(1, $days)];
    }

    private function resolveWebhookUrl(): string
    {
        $configured = trim((string) config('fluxpayments.webhook_url', ''));
        if ($configured !== '') {
            return $configured;
        }

        return rtrim((string) config('app.url'), '/').'/fluxpayments/webhook';
    }

    private function toCents(float $amountReais): int
    {
        return (int) round($amountReais * 100);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function extractErrorMessage(array $body, string $fallback): string
    {
        if (! empty($body['message']) && is_string($body['message'])) {
            return $body['message'];
        }

        if (! empty($body['error']) && is_string($body['error'])) {
            return $body['error'];
        }

        $details = $body['details'] ?? null;
        if (is_array($details) && $details !== []) {
            $first = $details[0];
            if (is_array($first) && ! empty($first['message']) && is_string($first['message'])) {
                return $first['message'];
            }
        }

        return $fallback;
    }
}
