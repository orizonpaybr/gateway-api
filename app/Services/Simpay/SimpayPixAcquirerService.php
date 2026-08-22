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

        // idempotency-key estável por saque (UUIDv5 do correlationId): um retry
        // de rede reusa a MESMA chave e a SIMPAY não duplica o cash-out.
        $idempotencyKey = ($correlationId !== null && $correlationId !== '')
            ? \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'simpay-payout:'.$correlationId)->toString()
            : (string) \Illuminate\Support\Str::uuid();

        $headers = array_merge($this->auth->authHeaders(), [
            'hmac' => $hmac,
            'idempotency-key' => $idempotencyKey,
        ]);

        $url = $this->baseUrl . '/create-cashout-self-approve/';

        try {
            $response = SecureHttp::post($url, $payload, $headers, $this->timeout);
            $body = $response->json();

            if ($this->isTokenExpired($response, $body)) {
                $this->auth->invalidateToken();
                $headers = array_merge($this->auth->authHeaders(), ['hmac' => $hmac, 'idempotency-key' => $idempotencyKey]);
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

            // Timeout/erro de rede: o Pix Out PODE ter sido executado — não estornar.
            return [
                'success' => false,
                'indeterminate' => true,
                'message' => 'Erro ao conectar com SIMPAY: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Decodifica um copia-e-cola PIX e retorna os dados do recebedor — usado pelo
     * cliente para CONFERIR quem vai receber antes de pagar (nome, CPF/CNPJ, banco).
     * POST /v2/finance/decode-qrcode
     *
     * @return array{success:bool,data?:array,message?:string}
     */
    public function decodeQrCode(string $pixCopyPaste, ?string $paymentDate = null): array
    {
        $code = trim($pixCopyPaste);
        if ($code === '') {
            return ['success' => false, 'message' => 'Copia-e-cola (pix_copy_and_paste) obrigatório.'];
        }

        $payload = ['pix_copy_and_paste' => $code];
        if ($paymentDate !== null && trim($paymentDate) !== '') {
            $payload['payment_date'] = trim($paymentDate);
        }

        $hmac = SimpayHmacHelper::generate($payload);
        $headers = array_merge($this->auth->authHeaders(), ['hmac' => $hmac]);
        $url = $this->baseUrl . '/decode-qrcode/';

        try {
            $response = SecureHttp::post($url, $payload, $headers, $this->timeout);
            $body = $response->json();

            if ($this->isTokenExpired($response, $body)) {
                $this->auth->invalidateToken();
                $headers = array_merge($this->auth->authHeaders(), ['hmac' => $hmac]);
                $response = SecureHttp::post($url, $payload, $headers, $this->timeout);
                $body = $response->json();
            }

            if (! $response->successful() || ! is_array($body)) {
                $msg = is_array($body)
                    ? ($body['message'] ?? $body['detail'] ?? 'Erro ao decodificar QR na SIMPAY.')
                    : 'Erro ao decodificar QR na SIMPAY.';

                return ['success' => false, 'message' => $msg];
            }

            return ['success' => true, 'data' => $body];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Erro ao conectar com SIMPAY: ' . $e->getMessage()];
        }
    }

    /**
     * Saldo da conta master SIMPAY (visibilidade operacional).
     * GET /v2/finance/get-balance?account_branch_identifier=&account_number=
     *
     * GET sem body → Bearer (mesmo padrão dos status endpoints). Se a SIMPAY
     * exigir hmac no GET de saldo, o smoke-test acusa e adicionamos a assinatura.
     *
     * @return array{success:bool,data?:array,message?:string}
     */
    public function getBalances(): array
    {
        $branch = trim((string) config('simpay.source_account_branch', '0001'));
        $number = trim((string) config('simpay.source_account_number', ''));
        if ($number === '') {
            return ['success' => false, 'message' => 'Conta de origem SIMPAY (SIMPAY_SOURCE_ACCOUNT_NUMBER) não configurada.'];
        }

        $url = $this->baseUrl . '/get-balance';
        $query = ['account_branch_identifier' => $branch, 'account_number' => $number];

        try {
            $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout, $query);
            $body = $response->json();

            if ($this->isTokenExpired($response, $body)) {
                $this->auth->invalidateToken();
                $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout, $query);
                $body = $response->json();
            }

            if (! $response->successful() || ! is_array($body)) {
                $msg = is_array($body)
                    ? ($body['message'] ?? $body['detail'] ?? 'Erro ao consultar saldo SIMPAY.')
                    : 'Erro ao consultar saldo SIMPAY.';

                return ['success' => false, 'message' => $msg];
            }

            return ['success' => true, 'data' => $body];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Erro ao conectar com SIMPAY: ' . $e->getMessage()];
        }
    }

    public function getPayoutStatus(string $transactionId, ?string $e2eId = null): array
    {
        $tid = trim($transactionId);
        $e2e = $e2eId !== null ? trim($e2eId) : '';

        if ($tid === '' && $e2e === '') {
            return [
                'success' => false,
                'message' => 'Informe transaction_id ou e2e_id.',
            ];
        }

        $query = [];
        if ($tid !== '') {
            $query['id'] = $tid;
        }
        if ($e2e !== '') {
            $query['e2e_id'] = $e2e;
        }

        $base = rtrim($this->baseUrl, '/');
        // Barra final alinhada aos outros endpoints Simpay; query via Laravel Http (evita perda de parâmetros).
        $path = $base.'/status-cashout/';

        try {
            $response = SecureHttp::get($path, $this->auth->authHeaders(), $this->timeout, $query);
            $body = $response->json();

            if ($this->isTokenExpired($response, $body)) {
                $this->auth->invalidateToken();
                $response = SecureHttp::get($path, $this->auth->authHeaders(), $this->timeout, $query);
                $body = $response->json();
            }

            if (! $response->successful() || empty($body['worked'])) {
                $errorMsg = is_array($body)
                    ? ($body['message'] ?? $body['detail'] ?? 'Transação não encontrada')
                    : 'Transação não encontrada';

                Log::warning('[SIMPAY][STATUS] Falha ao consultar status do payout', [
                    'transaction_id' => $tid !== '' ? $tid : null,
                    'e2e_id' => $e2e !== '' ? $e2e : null,
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
                'transaction_id' => $tid !== '' ? $tid : null,
                'e2e_id' => $e2e !== '' ? $e2e : null,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com SIMPAY: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Dados detalhados / comprovante de transação PIX (cash out) na Simpay.
     *
     * GET /v2/finance/receipt-transaction/file?id=&uuid=&language=
     * Documentação Simpay: transaction_id do cash out ou EndToEnd (operationUuid).
     *
     * @param  int|string|null  $id  transaction_id numérico retornado no PIX Transfer
     * @param  string|null  $uuid  End-to-end / operationUuid
     * @return array{success: bool, data?: array, message?: string, http_status?: int, raw?: array|null}
     */
    public function getReceiptTransaction(int|string|null $id = null, ?string $uuid = null, string $language = 'portuguese'): array
    {
        $uuidTrim = $uuid !== null ? trim($uuid) : '';
        $hasId = $id !== null && $id !== '';
        if (! $hasId && $uuidTrim === '') {
            return [
                'success' => false,
                'message' => 'Informe id (transaction_id do cash out) ou uuid (EndToEnd / operationUuid).',
            ];
        }

        $query = ['language' => $language !== '' ? $language : 'portuguese'];
        if ($hasId) {
            $query['id'] = $id;
        }
        if ($uuidTrim !== '') {
            $query['uuid'] = $uuidTrim;
        }

        $url = $this->baseUrl.'/receipt-transaction/file?'.http_build_query($query);

        try {
            $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout);
            $body = $response->json();

            if ($this->isTokenExpired($response, $body)) {
                $this->auth->invalidateToken();
                $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout);
                $body = $response->json();
            }

            if (! $response->successful() || empty($body['worked'])) {
                $rawText = $response->body();
                $decoded = is_array($body) ? $body : null;
                if ($decoded !== null) {
                    $errorMsg = $decoded['message'] ?? $decoded['detail'] ?? 'Transação não encontrada ou comprovante indisponível';
                } else {
                    $errorMsg = 'Comprovante indisponível (HTTP '.$response->status()
                        .'). Resposta não-JSON — comum em PIX_CASHOUT_ERROR (sem arquivo de recibo). Use status-cashout.';
                }

                Log::warning('[SIMPAY][RECEIPT] Falha ao consultar comprovante', [
                    'id' => $id,
                    'uuid' => $uuidTrim !== '' ? $uuidTrim : null,
                    'http_status' => $response->status(),
                    'error' => $errorMsg,
                    'body_preview' => $rawText !== '' ? mb_substr($rawText, 0, 500) : null,
                ]);

                $rawOut = $decoded;
                if ($rawOut === null && $rawText !== '') {
                    $rawOut = ['_response_preview' => mb_substr($rawText, 0, 2000)];
                }

                return [
                    'success' => false,
                    'message' => $errorMsg,
                    'http_status' => $response->status(),
                    'raw' => $rawOut,
                ];
            }

            if (! is_array($body)) {
                return [
                    'success' => false,
                    'message' => 'Resposta JSON inválida da Simpay.',
                    'http_status' => $response->status(),
                ];
            }

            Log::info('[SIMPAY][RECEIPT] Comprovante obtido', [
                'id' => $id,
                'uuid' => $uuidTrim !== '' ? $uuidTrim : null,
                'status' => $body['status'] ?? null,
                'transaction_id' => $body['transaction_id'] ?? null,
            ]);

            return [
                'success' => true,
                'data' => $body,
            ];
        } catch (\Throwable $e) {
            Log::error('[SIMPAY][RECEIPT] Exceção ao consultar comprovante', [
                'id' => $id,
                'uuid' => $uuidTrim !== '' ? $uuidTrim : null,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com SIMPAY: '.$e->getMessage(),
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
            'CANCELED', 'CANCELLED' => 'CANCELLED',
            'ERROR', 'FAILED' => 'FAILED',
            'REFUNDED' => 'REFUNDED',
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
