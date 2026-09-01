<?php

namespace App\Services\Paytler;

use App\Helpers\SecureHttp;
use App\Services\PixAcquirer\PixAcquirerInterface;
use Illuminate\Support\Facades\Log;

/**
 * Adaptador PIX Paytler (PixAcquirerInterface). Bearer JWT, sem HMAC.
 * Base: https://api.paytler.com/v1/customers. Doc: https://api.paytler.com/v1/docs
 */
class PaytlerPixAcquirerService implements PixAcquirerInterface
{
    private PaytlerAuthService $auth;
    private string $baseUrl;
    private int $timeout;

    public function __construct(PaytlerAuthService $auth)
    {
        $this->auth = $auth;
        $this->baseUrl = rtrim((string) config('paytler.base_url'), '/');
        $this->timeout = (int) config('paytler.timeout', 30);
    }

    public function getReference(): string
    {
        return 'paytler';
    }

    public function isActive(): bool
    {
        return ! empty(config('paytler.client_id'))
            && ! empty(config('paytler.client_secret'));
    }

    public function createCharge(
        float $amountReais,
        array $customer,
        ?string $correlationId = null,
        ?string $comment = null,
        ?string $expiresDate = null
    ): array {
        $externalId = ($correlationId !== null && $correlationId !== '')
            ? $correlationId
            : (string) \Illuminate\Support\Str::uuid();

        $payload = [
            'amount' => round($amountReais, 2),
            'externalId' => $externalId,
        ];

        if ($comment !== null && trim($comment) !== '') {
            $payload['description'] = mb_substr(trim($comment), 0, 140);
        }

        $expiresInSeconds = $this->expiresInSecondsFromDate($expiresDate);
        if ($expiresInSeconds !== null) {
            $payload['expiresInSeconds'] = $expiresInSeconds;
        }

        $payer = [];
        $document = preg_replace('/\D/', '', (string) ($customer['document'] ?? ''));
        $name = trim((string) ($customer['name'] ?? ''));
        if ($document !== '') {
            $payer['document'] = $document;
        }
        if ($name !== '') {
            $payer['name'] = mb_substr($name, 0, 60);
        }
        $email = trim((string) ($customer['email'] ?? ''));
        if ($email !== '') {
            $payer['email'] = $email;
        }
        $phone = preg_replace('/\D/', '', (string) ($customer['phone'] ?? ''));
        if ($phone !== '') {
            $payer['phone'] = $phone;
        }
        if ($document !== '' && $name !== '') {
            $payload['payer'] = $payer;
        }

        $url = $this->baseUrl.'/pix/create-immediate-qrcode';

        try {
            [$response, $body] = $this->postWithRetry($url, $payload);

            if (! $response->successful() || ! is_array($body) || empty($body['data'])) {
                $errorMsg = $this->errorMessage($body, 'Erro ao gerar cobrança PIX');

                Log::error('[PAYTLER][CHARGE] Falha ao gerar cash in', [
                    'status' => $response->status(),
                    'error' => $errorMsg,
                    'amount' => $amountReais,
                    'correlation_id' => $externalId,
                ]);

                return ['success' => false, 'message' => $errorMsg];
            }

            $data = $body['data'];
            $brCode = (string) ($data['key'] ?? '');         // EMV copia-e-cola
            $providerId = (string) ($data['uuid'] ?? '');    // id da transação na Paytler

            Log::info('[PAYTLER][CHARGE] Cash in gerado', [
                'uuid' => $providerId,
                'external_id' => $externalId,
                'amount' => $amountReais,
            ]);

            return [
                'success' => true,
                'brCode' => $brCode,
                'qrCodeImage' => null, // Paytler devolve só o EMV; o front gera o QR a partir dele.
                'correlationID' => $providerId !== '' ? $providerId : $externalId,
                'status' => 'created',
                'raw' => [
                    'uuid' => $data['uuid'] ?? null,
                    'external_id' => $externalId,
                    'identifier' => $data['identifier'] ?? null,
                    'expire' => $data['expire'] ?? null,
                    'documentNumber' => $data['documentNumber'] ?? null,
                    'name' => $data['name'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('[PAYTLER][CHARGE] Exceção ao gerar cash in', [
                'error' => $e->getMessage(),
                'amount' => $amountReais,
                'correlation_id' => $externalId,
            ]);

            return ['success' => false, 'message' => 'Erro ao conectar com PAYTLER: '.$e->getMessage()];
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
        $document = preg_replace('/\D/', '', (string) $recipientDocument);
        $name = trim((string) $recipientName);

        // externalId é OBRIGATÓRIO no withdraw da Paytler — sempre presente
        // (fallback pra UUID se não vier correlationId).
        $externalId = ($correlationId !== null && $correlationId !== '')
            ? $correlationId
            : (string) \Illuminate\Support\Str::uuid();

        $payload = [
            'amount' => round($amountReais, 2),
            'key' => $pixKey,
            'name' => $name !== '' ? $name : 'Recebedor',
            'documentNumber' => $document,
            'externalId' => $externalId,
            'notify' => true,
        ];

        if ($description !== null && trim($description) !== '') {
            $payload['description'] = mb_substr(trim($description), 0, 140);
        }
        // Sem CPF/CNPJ do titular não dá pra validar a chave contra o documento.
        if ($document === '') {
            $payload['skipKeyValidation'] = true;
        }

        $url = $this->baseUrl.'/pix/withdraw';

        try {
            [$response, $body] = $this->postWithRetry($url, $payload);

            if (! $response->successful() || ! is_array($body) || empty($body['data'])) {
                $errorMsg = $this->errorMessage($body, 'Erro desconhecido no cash out');

                Log::error('[PAYTLER][PAYOUT] Falha no cash out', [
                    'status' => $response->status(),
                    'error' => $errorMsg,
                    'pix_key_type' => $pixKeyType,
                    'amount' => $amountReais,
                    'correlation_id' => $correlationId,
                ]);

                return ['success' => false, 'message' => $errorMsg, 'raw' => is_array($body) ? $body : []];
            }

            $data = $body['data'];
            $transactionId = (string) ($body['transactionId'] ?? $data['uuid'] ?? '');
            $providerStatus = strtoupper((string) ($data['status'] ?? 'NEW'));

            Log::info('[PAYTLER][PAYOUT] Cash out aceito', [
                'transaction_id' => $transactionId,
                'status' => $providerStatus,
                'amount' => $amountReais,
                'correlation_id' => $correlationId,
            ]);

            return [
                'success' => true,
                'referenceCode' => $transactionId,
                'status' => $this->mapPayoutStatus($providerStatus),
                'raw' => [
                    'transaction_id' => $body['transactionId'] ?? null,
                    'uuid' => $data['uuid'] ?? null,
                    'endToEndId' => $data['endtoendId'] ?? null,
                    'provider_status' => $providerStatus,
                    'external_id' => $data['externalId'] ?? ($correlationId ?? null),
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('[PAYTLER][PAYOUT] Exceção ao processar cash out', [
                'error' => $e->getMessage(),
                'pix_key_type' => $pixKeyType,
                'amount' => $amountReais,
                'correlation_id' => $correlationId,
            ]);

            // Timeout/erro de rede: o Pix Out PODE ter saído — não estornar.
            return [
                'success' => false,
                'indeterminate' => true,
                'message' => 'Erro ao conectar com PAYTLER: '.$e->getMessage(),
            ];
        }
    }

    public function getPayoutStatus(string $transactionId, ?string $e2eId = null): array
    {
        $tid = trim($transactionId);
        $e2e = $e2eId !== null ? trim($e2eId) : '';

        if ($tid === '' && $e2e === '') {
            return ['success' => false, 'message' => 'Informe transactionId ou endToEnd.'];
        }

        $query = [];
        if ($tid !== '') {
            $query['transactionId'] = $tid;
        }
        if ($e2e !== '') {
            $query['endToEnd'] = $e2e;
        }

        try {
            [$response, $body] = $this->getWithRetry($this->baseUrl.'/pix/transaction', $query);

            if (! $response->successful() || ! is_array($body) || empty($body['data'])) {
                $errorMsg = $this->errorMessage($body, 'Transação não encontrada');

                Log::warning('[PAYTLER][STATUS] Falha ao consultar status do payout', [
                    'transaction_id' => $tid !== '' ? $tid : null,
                    'e2e' => $e2e !== '' ? $e2e : null,
                    'http_status' => $response->status(),
                    'error' => $errorMsg,
                ]);

                return ['success' => false, 'message' => $errorMsg, 'http_status' => $response->status()];
            }

            $data = $body['data'];
            $providerStatus = strtoupper((string) ($data['status'] ?? ''));

            return [
                'success' => true,
                'status' => $this->mapPayoutStatus($providerStatus),
                'raw' => [
                    'transaction_id' => $data['transactionId'] ?? null,
                    'uuid' => $data['uuid'] ?? null,
                    'endToEndId' => $data['endtoendId'] ?? null,
                    'provider_status' => $providerStatus,
                    'external_id' => $data['externalId'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('[PAYTLER][STATUS] Exceção ao consultar status do payout', [
                'transaction_id' => $tid !== '' ? $tid : null,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Erro ao conectar com PAYTLER: '.$e->getMessage()];
        }
    }

    public function getChargeStatus(string $transactionId): array
    {
        $tid = trim($transactionId);
        if ($tid === '') {
            return ['success' => false, 'message' => 'transactionId obrigatório.'];
        }

        try {
            [$response, $body] = $this->getWithRetry($this->baseUrl.'/pix/transaction', ['transactionId' => $tid]);

            if (! $response->successful() || ! is_array($body) || empty($body['data'])) {
                $errorMsg = $this->errorMessage($body, 'Cobrança não encontrada');

                Log::warning('[PAYTLER][CHARGE_STATUS] Falha ao consultar status do cash in', [
                    'transaction_id' => $tid,
                    'http_status' => $response->status(),
                    'error' => $errorMsg,
                ]);

                return ['success' => false, 'message' => $errorMsg];
            }

            $data = $body['data'];
            $providerStatus = strtoupper((string) ($data['status'] ?? ''));

            return [
                'success' => true,
                'status' => $this->mapChargeStatus($providerStatus),
                'raw' => [
                    'transaction_id' => $data['transactionId'] ?? null,
                    'uuid' => $data['uuid'] ?? null,
                    'endToEndId' => $data['endtoendId'] ?? null,
                    'amount' => $data['amount'] ?? null,
                    'payment_date' => $data['createdAt'] ?? null,
                    'provider_status' => $providerStatus,
                    'external_id' => $data['externalId'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('[PAYTLER][CHARGE_STATUS] Exceção ao consultar status do cash in', [
                'transaction_id' => $tid,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Erro ao conectar com PAYTLER: '.$e->getMessage()];
        }
    }

    /**
     * Devolução de um PIX recebido (cash in). Paytler identifica pelo endToEnd,
     * então $transactionId aqui deve ser o end_to_end do depósito (ver FinancialService::refundDeposit).
     */
    public function createRefund(string $transactionId, float $amount, string $reason): array
    {
        $end2end = trim($transactionId);
        if ($end2end === '') {
            return ['success' => false, 'message' => 'end_to_end obrigatório para devolução Paytler.'];
        }

        // externalId da DEVOLUÇÃO precisa ser único (é chave de idempotência na Paytler).
        // Reaproveitar o externalId do cash-in colide ("outra transação com o mesmo
        // externalId") e a devolução falha. O vínculo cash-in ↔ estorno é o end2end, NÃO
        // o externalId. UUID novo por tentativa também permite retentar após uma falha.
        $payload = [
            'end2end' => $end2end,
            'amount' => round($amount, 2),
            'description' => $reason !== '' ? mb_substr($reason, 0, 140) : 'Devolução',
            'externalId' => 'rev-'.\Illuminate\Support\Str::uuid(),
        ];

        $url = $this->baseUrl.'/pix/reverse-pix-in';

        try {
            [$response, $body] = $this->postWithRetry($url, $payload);

            if (! $response->successful() || ! is_array($body)) {
                $errorMsg = $this->errorMessage($body, 'Erro ao processar reembolso');

                Log::error('[PAYTLER][REFUND] Falha ao solicitar reembolso', [
                    'status' => $response->status(),
                    'error' => $errorMsg,
                    'end_to_end' => $end2end,
                    'amount' => $amount,
                ]);

                return ['success' => false, 'message' => $errorMsg];
            }

            $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
            // O id da devolução vem no TOPO da resposta (body.transactionId), não em data.
            // É por ele que consultamos o status depois (getRefundStatus).
            $refundId = (string) ($body['transactionId'] ?? $data['uuid'] ?? $data['transactionId'] ?? '');
            $refundStatus = strtoupper((string) ($data['status'] ?? 'NEW'));

            Log::info('[PAYTLER][REFUND] Devolução solicitada (assíncrona)', [
                'refund_id' => $refundId,
                'end_to_end' => $end2end,
                'amount' => $amount,
                'status' => $refundStatus,
            ]);

            // Devolução Paytler é ASSÍNCRONA (retorna NEW; processa em segundos e pode
            // FALHAR). Sinaliza async pra o refundDeposit NÃO decrementar o saldo antes
            // de confirmar via getRefundStatus / webhook PIX_REFUND.
            return [
                'success' => true,
                'async' => true,
                'refundId' => $refundId,
                'status' => $refundStatus,
                'raw' => $data,
            ];
        } catch (\Throwable $e) {
            Log::error('[PAYTLER][REFUND] Exceção ao solicitar reembolso', [
                'end_to_end' => $end2end,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Erro ao conectar com PAYTLER: '.$e->getMessage()];
        }
    }

    /**
     * Status de uma DEVOLUÇÃO (reverse-pix-in) pelo id retornado no createRefund.
     * Async: NEW/PROCESSING -> PROCESSING; REFUNDED/COMPLETED -> REFUNDED; erro -> FAILED.
     * O e2e original da devolução fica em `chargerBackId` (endtoendId pode vir vazio).
     *
     * @return array{success:bool,status?:string,provider_status?:string,charger_back_id?:?string,message?:string,http_status?:int}
     */
    public function getRefundStatus(string $reversalId): array
    {
        $id = trim($reversalId);
        if ($id === '') {
            return ['success' => false, 'message' => 'id da devolução obrigatório.'];
        }

        try {
            [$response, $body] = $this->getWithRetry($this->baseUrl.'/pix/transaction', ['transactionId' => $id]);

            if (! $response->successful() || ! is_array($body) || empty($body['data'])) {
                return [
                    'success' => false,
                    'message' => $this->errorMessage($body, 'Devolução não encontrada'),
                    'http_status' => $response->status(),
                ];
            }

            $data = $body['data'];
            $providerStatus = strtoupper((string) ($data['status'] ?? ''));

            return [
                'success' => true,
                'status' => $this->mapRefundStatus($providerStatus),
                'provider_status' => $providerStatus,
                'charger_back_id' => $data['chargerBackId'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Erro ao conectar com PAYTLER: '.$e->getMessage()];
        }
    }

    private function mapRefundStatus(string $providerStatus): string
    {
        return match (strtoupper($providerStatus)) {
            'REFUNDED', 'COMPLETED' => 'REFUNDED',
            'ERROR', 'FAILED', 'CANCEL', 'CANCELED', 'CANCELLED', 'DROP' => 'FAILED',
            default => 'PROCESSING', // NEW / QUEUED / AWAITING / PROCESSING
        };
    }

    /**
     * Decodifica o copia-e-cola/EMV e retorna os dados do recebedor (conferência do cliente).
     * POST /pix/decode-brcode { emv }.
     *
     * @return array{success:bool,data?:array,message?:string}
     */
    public function decodeQrCode(string $pixCopyPaste, ?string $paymentDate = null): array
    {
        $emv = trim($pixCopyPaste);
        if ($emv === '') {
            return ['success' => false, 'message' => 'Copia-e-cola (emv) obrigatório.'];
        }

        try {
            [$response, $body] = $this->postWithRetry($this->baseUrl.'/pix/decode-brcode', ['emv' => $emv]);

            if (! $response->successful() || ! is_array($body)) {
                return ['success' => false, 'message' => $this->errorMessage($body, 'Erro ao decodificar QR na PAYTLER.')];
            }

            return ['success' => true, 'data' => $body['data'] ?? $body];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Erro ao conectar com PAYTLER: '.$e->getMessage()];
        }
    }

    /**
     * Saldo da conta Paytler (visibilidade operacional). GET /account/balance.
     *
     * @return array{success:bool,data?:array,message?:string}
     */
    public function getBalances(): array
    {
        try {
            [$response, $body] = $this->getWithRetry($this->baseUrl.'/account/balance', []);

            if (! $response->successful() || ! is_array($body)) {
                return ['success' => false, 'message' => $this->errorMessage($body, 'Erro ao consultar saldo PAYTLER.')];
            }

            return ['success' => true, 'data' => $body];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Erro ao conectar com PAYTLER: '.$e->getMessage()];
        }
    }

    public function mapPayoutStatus(string $providerStatus): string
    {
        return match (strtoupper($providerStatus)) {
            'COMPLETED' => 'COMPLETED',
            'NEW' => 'PENDING',
            'QUEUED', 'AWAITING', 'PROCESSING' => 'PROCESSING',
            'ERROR', 'FAILED' => 'FAILED',
            'CANCEL', 'CANCELED', 'CANCELLED', 'DROP' => 'CANCELLED',
            'REFUNDED' => 'REFUNDED',
            default => 'PROCESSING',
        };
    }

    private function mapChargeStatus(string $providerStatus): string
    {
        return match (strtoupper($providerStatus)) {
            'COMPLETED' => 'PAID_OUT',
            'REFUNDED' => 'REFUNDED',
            'NEW', 'QUEUED', 'AWAITING', 'PROCESSING' => 'WAITING_FOR_APPROVAL',
            'ERROR', 'FAILED', 'CANCEL', 'CANCELED', 'CANCELLED', 'DROP' => 'CANCELED',
            default => 'WAITING_FOR_APPROVAL',
        };
    }

    /**
     * POST com um retry após invalidar o token (401 = Bearer expirado).
     *
     * @param  array<string, mixed>  $payload
     * @return array{0:\Illuminate\Http\Client\Response,1:mixed}
     */
    private function postWithRetry(string $url, array $payload): array
    {
        $response = SecureHttp::post($url, $payload, $this->auth->authHeaders(), $this->timeout);
        $body = $response->json();

        if ($response->status() === 401) {
            $this->auth->invalidateToken();
            $response = SecureHttp::post($url, $payload, $this->auth->authHeaders(), $this->timeout);
            $body = $response->json();
        }

        return [$response, $body];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{0:\Illuminate\Http\Client\Response,1:mixed}
     */
    private function getWithRetry(string $url, array $query): array
    {
        $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout, $query);
        $body = $response->json();

        if ($response->status() === 401) {
            $this->auth->invalidateToken();
            $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout, $query);
            $body = $response->json();
        }

        return [$response, $body];
    }

    /**
     * Paytler devolve erro como { message: string|string[], error } (ErrorResponseDto).
     */
    private function errorMessage(mixed $body, string $default): string
    {
        if (! is_array($body)) {
            return $default;
        }
        $msg = $body['message'] ?? $body['error'] ?? $default;
        if (is_array($msg)) {
            $msg = implode('; ', array_map('strval', $msg));
        }

        return (string) $msg;
    }

    private function expiresInSecondsFromDate(?string $expiresDate): ?int
    {
        if ($expiresDate === null || trim($expiresDate) === '') {
            return null;
        }
        try {
            // Subtração de timestamp: inequívoco (futuro - agora), sem depender da
            // semântica de diffInSeconds (que varia entre versões do Carbon).
            $seconds = \Carbon\Carbon::parse($expiresDate)->getTimestamp() - now()->getTimestamp();

            return $seconds > 0 ? $seconds : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
