<?php

namespace App\Services\Simpay;

use App\Helpers\SecureHttp;
use App\Services\PixAcquirer\PixAcquirerInterface;
use Illuminate\Support\Facades\Log;

class SimpayPixAcquirerService implements PixAcquirerInterface
{
    private SimpayAuthService $auth;
    private string $baseUrl;
    private int $timeout;

    public function __construct(SimpayAuthService $auth)
    {
        $this->auth = $auth;
        $this->baseUrl = rtrim((string) config('simpay.base_url'), '/');
        $this->timeout = (int) config('simpay.timeout', 30);
    }

    public function getReference(): string
    {
        return 'simpay';
    }

    public function isActive(): bool
    {
        return ! empty(config('simpay.client_id'))
            && ! empty(config('simpay.client_secret'))
            && ! empty(config('simpay.hmac_key'))
            && ! empty(config('simpay.source_account_number'));
    }

    public function createCharge(
        float $amountReais,
        array $customer,
        ?string $correlationId = null,
        ?string $comment = null,
        ?string $expiresDate = null
    ): array {
        $payload = [
            'amount' => round($amountReais, 2),
            'type_fine' => 'NONE',
            'fine' => 0,
            'source_account_branch_identifier' => (string) config('simpay.source_account_branch', '0001'),
            'source_account_number' => (string) config('simpay.source_account_number'),
            'base_64_image' => true,
        ];

        $debtorName = trim((string) ($customer['name'] ?? ''));
        if ($debtorName !== '') {
            $payload['debtor_name'] = mb_substr($debtorName, 0, 25);
        }

        $document = preg_replace('/\D/', '', (string) ($customer['document'] ?? ''));
        if ($document !== '') {
            $payload['debtor_document'] = $document;
            $payload['type_document'] = strlen($document) <= 11 ? 'CPF' : 'CNPJ';
        }

        if ($correlationId !== null && $correlationId !== '') {
            $payload['tag'] = $correlationId;
        }

        $hmac = SimpayHmacHelper::generate($payload);

        $headers = array_merge($this->auth->authHeaders(), [
            'hmac' => $hmac,
        ]);

        $url = $this->baseUrl . '/create-pix-copy-and-paste/';

        try {
            $response = SecureHttp::post($url, $payload, $headers, $this->timeout);
            $body = $response->json();

            if ($this->isTokenExpired($response, $body)) {
                $this->auth->invalidateToken();
                $headers = array_merge($this->auth->authHeaders(), ['hmac' => $hmac]);
                $response = SecureHttp::post($url, $payload, $headers, $this->timeout);
                $body = $response->json();
            }

            if (! $response->successful() || empty($body['worked'])) {
                $errorMsg = $body['message'] ?? $body['detail'] ?? 'Erro ao gerar cobrança PIX';

                Log::error('[SIMPAY][CHARGE] Falha ao gerar cash in', [
                    'status' => $response->status(),
                    'error' => $errorMsg,
                    'amount' => $amountReais,
                    'correlation_id' => $correlationId,
                ]);

                return [
                    'success' => false,
                    'message' => $errorMsg,
                ];
            }

            $brCode = $body['pix_copy_and_paste'] ?? null;
            $qrCodeId = (string) ($body['qr_code_id'] ?? '');

            Log::info('[SIMPAY][CHARGE] Cash in gerado', [
                'qr_code_id' => $qrCodeId,
                'amount' => $amountReais,
                'correlation_id' => $correlationId,
                'status' => $body['status'] ?? 'NEW',
            ]);

            return [
                'success' => true,
                'brCode' => $brCode,
                'qrCodeImage' => $body['base_64_image_url'] ?? null,
                'correlationID' => $qrCodeId,
                'status' => strtolower((string) ($body['status'] ?? 'created')),
                'raw' => [
                    'qr_code_id' => $body['qr_code_id'] ?? null,
                    'tx_id' => $body['tx_id'] ?? null,
                    'base_64_image' => $body['base_64_image'] ?? null,
                    'expiration_date' => $body['expiration_date'] ?? null,
                    'fee' => $body['fee'] ?? $body['tax'] ?? null,
                ],
            ];

        } catch (\Throwable $e) {
            Log::error('[SIMPAY][CHARGE] Exceção ao gerar cash in', [
                'error' => $e->getMessage(),
                'amount' => $amountReais,
                'correlation_id' => $correlationId,
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com SIMPAY: ' . $e->getMessage(),
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
        $payload = [
            'source_account_branch_identifier' => (string) config('simpay.source_account_branch', '0001'),
            'source_account_number' => (string) config('simpay.source_account_number'),
            'amount' => round($amountReais, 2),
            'key' => $pixKey,
            'tag' => $correlationId ?? $description ?? '',
        ];

        $hmac = SimpayHmacHelper::generate($payload);

        $headers = array_merge($this->auth->authHeaders(), [
            'hmac' => $hmac,
        ]);

        $url = $this->baseUrl . '/create-cashout-self-approve/';

        try {
            $response = SecureHttp::post($url, $payload, $headers, $this->timeout);
            $body = $response->json();

            if ($this->isTokenExpired($response, $body)) {
                $this->auth->invalidateToken();
                $headers = array_merge($this->auth->authHeaders(), ['hmac' => $hmac]);
                $response = SecureHttp::post($url, $payload, $headers, $this->timeout);
                $body = $response->json();
            }

            if (! $response->successful() || empty($body['worked'])) {
                $errorMsg = $body['message'] ?? $body['detail'] ?? $body['erro_descriptor'] ?? 'Erro desconhecido';

                Log::error('[SIMPAY][PAYOUT] Falha no cash out', [
                    'status' => $response->status(),
                    'error' => $errorMsg,
                    'pix_key_type' => $pixKeyType,
                    'amount' => $amountReais,
                    'correlation_id' => $correlationId,
                ]);

                return [
                    'success' => false,
                    'message' => $errorMsg,
                    'raw' => $body ?? [],
                ];
            }

            $transactionId = (string) ($body['transaction_id'] ?? $body['id'] ?? '');
            $providerStatus = strtoupper((string) ($body['status'] ?? 'PROCESSING'));

            Log::info('[SIMPAY][PAYOUT] Cash out aceito', [
                'transaction_id' => $transactionId,
                'status' => $providerStatus,
                'amount' => $amountReais,
                'correlation_id' => $correlationId,
                'end_to_end' => $body['operationUuid'] ?? null,
            ]);

            return [
                'success' => true,
                'referenceCode' => $transactionId,
                'status' => $providerStatus,
                'raw' => [
                    'transaction_id' => $body['transaction_id'] ?? null,
                    'code_transaction' => $body['code_transaction'] ?? null,
                    'endToEndId' => $body['operationUuid'] ?? null,
                    'fee' => $body['fee'] ?? null,
                    'from_account' => $body['from_accout'] ?? null,
                    'recipient_name' => $body['recipient_name'] ?? null,
                    'recipient_legal_id' => $body['recipient_legal_id'] ?? null,
                    'recipient_instution' => $body['recipient_instution'] ?? null,
                    'recipient_account_id' => $body['recipient_account_id'] ?? null,
                    'erro_descriptor' => $body['erro_descriptor'] ?? null,
                ],
            ];

        } catch (\Throwable $e) {
            Log::error('[SIMPAY][PAYOUT] Exceção ao processar cash out', [
                'error' => $e->getMessage(),
                'pix_key_type' => $pixKeyType,
                'amount' => $amountReais,
                'correlation_id' => $correlationId,
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com SIMPAY: ' . $e->getMessage(),
            ];
        }
    }

    public function getPayoutStatus(string $transactionId): array
    {
        $url = $this->baseUrl . '/status-cashout/?id=' . urlencode($transactionId);

        try {
            $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout);
            $body = $response->json();

            if ($this->isTokenExpired($response, $body)) {
                $this->auth->invalidateToken();
                $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout);
                $body = $response->json();
            }

            if (! $response->successful() || empty($body['worked'])) {
                $errorMsg = $body['message'] ?? $body['detail'] ?? 'Transação não encontrada';

                Log::warning('[SIMPAY][STATUS] Falha ao consultar status do payout', [
                    'transaction_id' => $transactionId,
                    'http_status' => $response->status(),
                    'error' => $errorMsg,
                ]);

                return [
                    'success' => false,
                    'message' => $errorMsg,
                ];
            }

            $providerStatus = strtoupper((string) ($body['status'] ?? ''));

            return [
                'success' => true,
                'status' => $this->mapPayoutStatus($providerStatus),
                'raw' => [
                    'transaction_id' => $body['transaction_id'] ?? null,
                    'code_transaction' => $body['code_transaction'] ?? null,
                    'endToEndId' => $body['operationUuid'] ?? null,
                    'provider_status' => $providerStatus,
                    'fee' => $body['fee'] ?? null,
                    'recipient_name' => $body['recipient_name'] ?? null,
                    'recipient_legal_id' => $body['recipient_legal_id'] ?? null,
                    'erro_descriptor' => $body['erro_descriptor'] ?? null,
                ],
            ];

        } catch (\Throwable $e) {
            Log::error('[SIMPAY][STATUS] Exceção ao consultar status do payout', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com SIMPAY: ' . $e->getMessage(),
            ];
        }
    }

    public function getChargeStatus(string $transactionId): array
    {
        $url = $this->baseUrl . '/status-pix-copy-and-paste/?id=' . urlencode($transactionId);

        try {
            $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout);
            $body = $response->json();

            if ($this->isTokenExpired($response, $body)) {
                $this->auth->invalidateToken();
                $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout);
                $body = $response->json();
            }

            if (! $response->successful() || empty($body['worked'])) {
                $errorMsg = $body['message'] ?? $body['detail'] ?? 'Cobrança não encontrada';

                Log::warning('[SIMPAY][CHARGE_STATUS] Falha ao consultar status do cash in', [
                    'transaction_id' => $transactionId,
                    'http_status' => $response->status(),
                    'error' => $errorMsg,
                ]);

                return [
                    'success' => false,
                    'message' => $errorMsg,
                ];
            }

            $providerStatus = strtoupper((string) ($body['status'] ?? ''));
            $mappedStatus = $this->mapChargeStatus($providerStatus);

            return [
                'success' => true,
                'status' => $mappedStatus,
                'raw' => [
                    'qr_code_id' => $body['qr_code_id'] ?? null,
                    'tx_id' => $body['tx_id'] ?? null,
                    'endToEndId' => $body['endToEndId'] ?? null,
                    'payment_date' => $body['payment_date'] ?? null,
                    'amount' => $body['amount'] ?? null,
                    'amount_chargeback' => $body['amount_chargeback'] ?? null,
                    'fee' => $body['fee'] ?? $body['tax'] ?? null,
                    'provider_status' => $providerStatus,
                    'debtor_name' => $body['debtor_name'] ?? null,
                    'debtor_institution' => $body['debtor_institution'] ?? null,
                ],
            ];

        } catch (\Throwable $e) {
            Log::error('[SIMPAY][CHARGE_STATUS] Exceção ao consultar status do cash in', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com SIMPAY: ' . $e->getMessage(),
            ];
        }
    }

    public function createRefund(string $transactionId, float $amount, string $reason): array
    {
        $payload = [
            'qr_code_id' => (int) $transactionId,
            'amount' => round($amount, 2),
            'information' => $reason,
        ];

        $hmac = SimpayHmacHelper::generate($payload);

        $headers = array_merge($this->auth->authHeaders(), [
            'hmac' => $hmac,
        ]);

        $url = $this->baseUrl . '/chargebacks-pix-copy-and-paste/';

        try {
            $response = SecureHttp::post($url, $payload, $headers, $this->timeout);
            $body = $response->json();

            if ($this->isTokenExpired($response, $body)) {
                $this->auth->invalidateToken();
                $headers = array_merge($this->auth->authHeaders(), ['hmac' => $hmac]);
                $response = SecureHttp::post($url, $payload, $headers, $this->timeout);
                $body = $response->json();
            }

            if (! $response->successful() || empty($body['worked'])) {
                $errorMsg = $body['message'] ?? $body['detail'] ?? 'Erro ao processar reembolso';

                Log::error('[SIMPAY][REFUND] Falha ao solicitar reembolso', [
                    'status' => $response->status(),
                    'error' => $errorMsg,
                    'transaction_id' => $transactionId,
                    'amount' => $amount,
                ]);

                return [
                    'success' => false,
                    'message' => $errorMsg,
                ];
            }

            $refundId = (string) ($body['id'] ?? '');
            $refundStatus = strtoupper((string) ($body['status'] ?? 'PENDING'));

            Log::info('[SIMPAY][REFUND] Reembolso solicitado', [
                'refund_id' => $refundId,
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'status' => $refundStatus,
            ]);

            return [
                'success' => true,
                'refundId' => $refundId,
                'status' => $refundStatus,
                'raw' => [
                    'id' => $body['id'] ?? null,
                    'end_to_end_id' => $body['end_to_end_id'] ?? null,
                    'amount' => $body['amount'] ?? null,
                    'fee' => $body['fee'] ?? null,
                    'provider_status' => $refundStatus,
                ],
            ];

        } catch (\Throwable $e) {
            Log::error('[SIMPAY][REFUND] Exceção ao solicitar reembolso', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com SIMPAY: ' . $e->getMessage(),
            ];
        }
    }

    private function mapChargeStatus(string $providerStatus): string
    {
        return match (strtoupper($providerStatus)) {
            'PAID' => 'PAID_OUT',
            'CANCELED' => 'CANCELED',
            'CHARGEBACK' => 'REFUNDED',
            'NEW' => 'WAITING_FOR_APPROVAL',
            default => 'WAITING_FOR_APPROVAL',
        };
    }

    public function mapPayoutStatus(string $providerStatus): string
    {
        return match (strtoupper($providerStatus)) {
            'SUCCESS' => 'COMPLETED',
            'PROCESSING' => 'PROCESSING',
            'CANCELED' => 'CANCELLED',
            'NEW' => 'PENDING',
            default => 'PROCESSING',
        };
    }

    private function isTokenExpired($response, ?array $body): bool
    {
        if ($response->status() !== 401) {
            return false;
        }

        return ($body['code'] ?? '') === 'token_not_valid';
    }
}
