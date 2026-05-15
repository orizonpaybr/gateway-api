<?php

namespace App\Services\Fyhub;

use App\Helpers\PixApiErrorTypes;
use App\Helpers\SecureHttp;
use App\Services\FyhubContas\FyhubContasPixOutService;
use App\Services\PixAcquirer\PixAcquirerInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FyhubPixAcquirerService implements PixAcquirerInterface
{
    private string $baseUrl;
    private int $timeout;

    public function __construct(
        private readonly FyhubAuthService $auth,
        private readonly FyhubContasPixOutService $contasPixOut,
    ) {
        $this->baseUrl = rtrim((string) config('fyhub.base_url'), '/');
        $this->timeout = (int) config('fyhub.timeout', 30);
    }

    public function getReference(): string
    {
        return 'fyhub';
    }

    public function isActive(): bool
    {
        return ! empty(config('fyhub.client_id'))
            && ! empty(config('fyhub.client_secret'))
            && ! empty(config('fyhub.base_url'))
            && ! empty(config('fyhub.pix_key'));
    }

    public function createCharge(
        float $amountReais,
        array $customer,
        ?string $correlationId = null,
        ?string $comment = null,
        ?string $expiresDate = null
    ): array {
        $txid = $this->normalizeTxid($correlationId);
        $debtorName = trim((string) ($customer['name'] ?? ''));
        $document = preg_replace('/\D/', '', (string) ($customer['document'] ?? ''));
        $payload = [
            'calendario' => [
                'expiracao' => (int) config('fyhub.charge_expiration_seconds', 3600),
            ],
            'valor' => [
                'original' => number_format(round($amountReais, 2), 2, '.', ''),
                'modalidadeAlteracao' => (int) config('fyhub.allow_amount_change', false) ? 1 : 0,
            ],
            'chave' => (string) config('fyhub.pix_key'),
        ];

        $managedLocation = null;
        if ((bool) config('fyhub.use_managed_locations', true)) {
            $managedLocation = $this->createPayloadLocation();
            if (! ($managedLocation['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => $managedLocation['message'] ?? 'Não foi possível criar location na FYHUB.',
                ];
            }
            $payload['loc'] = [
                'id' => $managedLocation['id'],
            ];
        }

        if ($debtorName !== '' && $document !== '') {
            $payload['devedor'] = strlen($document) <= 11
                ? ['cpf' => substr($document, 0, 11), 'nome' => mb_substr($debtorName, 0, 200)]
                : ['cnpj' => substr($document, 0, 14), 'nome' => mb_substr($debtorName, 0, 200)];
        }

        if ($comment !== null && trim($comment) !== '') {
            $payload['solicitacaoPagador'] = mb_substr(trim($comment), 0, 140);
        }

        $url = $this->baseUrl.'/cob/'.$txid;

        try {
            $response = SecureHttp::put($url, $payload, $this->auth->authHeaders(), $this->timeout);
            $body = $response->json();

            if ($response->status() === 401) {
                $this->auth->invalidateToken();
                $response = SecureHttp::put($url, $payload, $this->auth->authHeaders(), $this->timeout);
                $body = $response->json();
            }

            if (! $response->successful() || ! is_array($body)) {
                $errorMsg = PixApiErrorTypes::getMessageFromResponse(
                    is_array($body) ? $body : null,
                    'Erro ao criar cobrança imediata na FYHUB.'
                );

                Log::error('[FYHUB][CHARGE] Falha ao criar cobrança', [
                    'status' => $response->status(),
                    'txid' => $txid,
                    'error' => $errorMsg,
                ]);

                return [
                    'success' => false,
                    'message' => $errorMsg,
                ];
            }

            $providerStatus = strtoupper((string) ($body['status'] ?? 'ATIVA'));
            $resolvedTxid = (string) ($body['txid'] ?? $txid);

            $pixCopiaECola = $body['pixCopiaECola']
                ?? $body['dadosQR']['pixCopiaECola']
                ?? null;

            if (is_string($pixCopiaECola) && trim($pixCopiaECola) !== '') {
                $brCode = trim($pixCopiaECola);
            } else {
                $brCode = $body['location'] ?? ($body['loc']['location'] ?? null);
            }

            return [
                'success' => true,
                'brCode' => $brCode,
                'correlationID' => $resolvedTxid,
                'status' => $this->mapChargeStatus($providerStatus),
                'raw' => [
                    'txid' => $resolvedTxid,
                    'revisao' => $body['revisao'] ?? null,
                    'location' => $body['location'] ?? null,
                    'loc' => $body['loc'] ?? null,
                    'loc_id' => $body['loc']['id'] ?? ($managedLocation['id'] ?? null),
                    'provider_status' => $providerStatus,
                    'chave' => $body['chave'] ?? null,
                    'pix_copia_e_cola' => $pixCopiaECola,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('[FYHUB][CHARGE] Exceção ao criar cobrança', [
                'txid' => $txid,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com FYHUB: '.$e->getMessage(),
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
        if (! $this->contasPixOut->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Cash-out FYHUB depende da API Contas: configure FYHUB_CONTAS_* (OAuth + certificado mTLS).',
            ];
        }

        $idempotencyKey = ($correlationId !== null && trim($correlationId) !== '')
            ? trim($correlationId)
            : (string) Str::uuid();

        try {
            $formattedKey = $this->contasPixOut->formatPixKeyForDict($pixKey, $pixKeyType);
            $body = $this->contasPixOut->buildDictPaymentBody(
                $formattedKey,
                $amountReais,
                $description,
                $recipientDocument
            );

            $result = $this->contasPixOut->initiatePaymentByDict($idempotencyKey, $body);

            if (! ($result['success'] ?? false)) {
                Log::error('[FYHUB][PAYOUT] Falha ao iniciar Pix pela API Contas', [
                    'status' => $result['status'] ?? null,
                    'message' => $result['message'] ?? null,
                ]);

                return [
                    'success' => false,
                    'message' => $result['message'] ?? 'Não foi possível iniciar o pagamento Pix na FYHUB Contas.',
                    'raw' => $result['data'] ?? [],
                ];
            }

            $data = is_array($result['data'] ?? null) ? $result['data'] : [];
            $e2e = trim((string) ($data['endToEndId'] ?? ''));
            $numericId = $data['id'] ?? null;
            $referenceCode = $e2e !== '' ? $e2e : (string) ($numericId ?? $idempotencyKey);

            $providerStatus = strtoupper((string) ($data['status'] ?? ''));
            if ($providerStatus === '') {
                $providerStatus = 'PROCESSING';
            }

            Log::info('[FYHUB][PAYOUT] Ordem aceita (API Contas)', [
                'end_to_end' => $e2e !== '' ? $e2e : null,
                'id' => $numericId,
                'idempotency_key' => $idempotencyKey,
            ]);

            return [
                'success' => true,
                'referenceCode' => $referenceCode,
                'status' => $providerStatus,
                'raw' => [
                    'endToEndId' => $e2e !== '' ? $e2e : null,
                    'id' => $numericId,
                    'idempotencyKey' => $idempotencyKey,
                    'eventDate' => $data['eventDate'] ?? null,
                    'type' => $data['type'] ?? null,
                    'payment' => $data['payment'] ?? null,
                    'http_status' => $result['status'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('[FYHUB][PAYOUT] Exceção ao iniciar Pix na API Contas', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com FYHUB Contas: '.$e->getMessage(),
            ];
        }
    }

    public function mapPayoutStatus(string $providerStatus): string
    {
        return match (strtoupper($providerStatus)) {
            'SUCCESS', 'PAID', 'COMPLETED', 'LIQUIDATED', 'SETTLED', 'CONFIRMED', 'ACCEPTED' => 'COMPLETED',
            'CANCELED', 'CANCELLED' => 'CANCELLED',
            'ERROR', 'FAILED', 'REJECTED' => 'FAILED',
            'REFUNDED' => 'REFUNDED',
            'PROCESSING', 'PENDING', 'SUBMITTED', 'QUEUED', 'AWAITING', 'NEW', 'CREATED' => 'PROCESSING',
            default => 'PROCESSING',
        };
    }

    public function getPayoutStatus(string $transactionId, ?string $e2eId = null): array
    {
        if (! $this->contasPixOut->isConfigured()) {
            return [
                'success' => false,
                'message' => 'API FYHUB Contas não configurada para consulta de pagamento.',
            ];
        }

        $e2e = trim((string) ($e2eId ?? ''));
        $tid = trim((string) $transactionId);
        if ($e2e === '' && str_starts_with($tid, 'E') && strlen($tid) > 10) {
            $e2e = $tid;
        }
        if ($e2e === '' && $tid !== '') {
            $e2e = $tid;
        }

        if ($e2e === '') {
            return [
                'success' => false,
                'message' => 'Informe endToEndId ou transaction_id (end-to-end) para consultar o pagamento na FYHUB Contas.',
            ];
        }

        try {
            $result = $this->contasPixOut->getPaymentByEndToEndId($e2e);

            if (! ($result['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => $result['message'] ?? 'Pagamento não encontrado na FYHUB Contas.',
                    'http_status' => $result['status'] ?? null,
                ];
            }

            $payload = is_array($result['data'] ?? null) ? $result['data'] : [];
            $row = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

            $providerStatus = strtoupper((string) ($row['status'] ?? ''));

            return [
                'success' => true,
                'status' => $this->mapPayoutStatus($providerStatus !== '' ? $providerStatus : 'PROCESSING'),
                'raw' => [
                    'endToEndId' => $row['endToEndId'] ?? $e2e,
                    'id' => $row['id'] ?? null,
                    'idempotencyKey' => $row['idempotencyKey'] ?? null,
                    'provider_status' => $providerStatus,
                    'errorCode' => $row['errorCode'] ?? null,
                    'payment' => $row['payment'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('[FYHUB][PAYOUT_STATUS] Exceção ao consultar pagamento', [
                'end_to_end' => $e2e,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com FYHUB Contas: '.$e->getMessage(),
            ];
        }
    }

    public function getChargeStatus(string $transactionId): array
    {
        $txid = $this->normalizeTxid($transactionId);
        $url = $this->baseUrl.'/cob/'.$txid;

        try {
            $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout);
            $body = $response->json();

            if ($response->status() === 401) {
                $this->auth->invalidateToken();
                $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout);
                $body = $response->json();
            }

            if (! $response->successful() || ! is_array($body)) {
                $errorMsg = PixApiErrorTypes::getMessageFromResponse(
                    is_array($body) ? $body : null,
                    'Erro ao consultar cobrança imediata na FYHUB.'
                );

                return [
                    'success' => false,
                    'message' => $errorMsg,
                ];
            }

            $providerStatus = strtoupper((string) ($body['status'] ?? 'ATIVA'));

            return [
                'success' => true,
                'status' => $this->mapChargeStatus($providerStatus),
                'raw' => [
                    'txid' => $body['txid'] ?? $txid,
                    'revisao' => $body['revisao'] ?? null,
                    'location' => $body['location'] ?? null,
                    'loc' => $body['loc'] ?? null,
                    'provider_status' => $providerStatus,
                    'valor' => $body['valor'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('[FYHUB][CHARGE_STATUS] Exceção ao consultar cobrança', [
                'txid' => $txid,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com FYHUB: '.$e->getMessage(),
            ];
        }
    }

    public function createRefund(string $transactionId, float $amount, string $reason): array
    {
        $e2eId = trim($transactionId);
        if ($e2eId === '') {
            return [
                'success' => false,
                'message' => 'EndToEndId é obrigatório para solicitar devolução na FYHUB.',
            ];
        }

        $refundId = strtolower(substr(bin2hex(random_bytes(6)), 0, 12));
        $url = $this->baseUrl.'/pix/'.$e2eId.'/devolucao/'.$refundId;
        $payload = [
            'valor' => number_format(round($amount, 2), 2, '.', ''),
            'natureza' => (string) config('fyhub.refund_nature', 'ORIGINAL'),
        ];
        if (trim($reason) !== '') {
            $payload['descricao'] = mb_substr(trim($reason), 0, 140);
        }

        try {
            $response = SecureHttp::put($url, $payload, $this->auth->authHeaders(), $this->timeout);
            $body = $response->json();

            if ($response->status() === 401) {
                $this->auth->invalidateToken();
                $response = SecureHttp::put($url, $payload, $this->auth->authHeaders(), $this->timeout);
                $body = $response->json();
            }

            if (! $response->successful() || ! is_array($body)) {
                $errorMsg = PixApiErrorTypes::getMessageFromResponse(
                    is_array($body) ? $body : null,
                    'Erro ao solicitar devolução na FYHUB.'
                );

                return [
                    'success' => false,
                    'message' => $errorMsg,
                    'raw' => is_array($body) ? $body : [],
                ];
            }

            return [
                'success' => true,
                'refundId' => (string) ($body['id'] ?? $refundId),
                'status' => (string) ($body['status'] ?? 'EM_PROCESSAMENTO'),
                'raw' => $body,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Erro ao conectar com FYHUB: '.$e->getMessage(),
            ];
        }
    }

    private function normalizeTxid(?string $txid): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9]/', '', (string) $txid);
        $normalized = strtolower((string) $normalized);
        if ($normalized === '') {
            $normalized = strtolower(bin2hex(random_bytes(16)));
        }

        return substr($normalized, 0, 35);
    }

    private function mapChargeStatus(string $providerStatus): string
    {
        return match (strtoupper($providerStatus)) {
            'CONCLUIDA' => 'PAID_OUT',
            'REMOVIDA_PELO_USUARIO_RECEBEDOR', 'REMOVIDA_PELO_PSP' => 'CANCELED',
            'ATIVA' => 'WAITING_FOR_APPROVAL',
            default => 'WAITING_FOR_APPROVAL',
        };
    }

    /**
     * @return array{success:bool,id?:int,location?:string,tipoCob?:string,raw?:array,message?:string}
     */
    public function createPayloadLocation(string $tipoCob = 'cob'): array
    {
        $url = $this->baseUrl.'/loc';
        $payload = ['tipoCob' => $tipoCob];

        try {
            $response = SecureHttp::post($url, $payload, $this->auth->authHeaders(), $this->timeout);
            $body = $response->json();

            if ($response->status() === 401) {
                $this->auth->invalidateToken();
                $response = SecureHttp::post($url, $payload, $this->auth->authHeaders(), $this->timeout);
                $body = $response->json();
            }

            if (! $response->successful() || ! is_array($body) || ! isset($body['id'])) {
                $errorMsg = PixApiErrorTypes::getMessageFromResponse(
                    is_array($body) ? $body : null,
                    'Erro ao criar location na FYHUB.'
                );

                Log::error('[FYHUB][LOC] Falha ao criar location', [
                    'status' => $response->status(),
                    'error' => $errorMsg,
                    'tipo_cob' => $tipoCob,
                ]);

                return [
                    'success' => false,
                    'message' => $errorMsg,
                ];
            }

            return [
                'success' => true,
                'id' => (int) $body['id'],
                'location' => isset($body['location']) ? (string) $body['location'] : null,
                'tipoCob' => isset($body['tipoCob']) ? (string) $body['tipoCob'] : $tipoCob,
                'raw' => $body,
            ];
        } catch (\Throwable $e) {
            Log::error('[FYHUB][LOC] Exceção ao criar location', [
                'error' => $e->getMessage(),
                'tipo_cob' => $tipoCob,
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com FYHUB: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array{success:bool,data?:array,message?:string}
     */
    public function getPayloadLocation(string|int $id): array
    {
        $locationId = trim((string) $id);
        if ($locationId === '') {
            return [
                'success' => false,
                'message' => 'Informe o id da location.',
            ];
        }

        $url = $this->baseUrl.'/loc/'.$locationId;

        try {
            $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout);
            $body = $response->json();

            if ($response->status() === 401) {
                $this->auth->invalidateToken();
                $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout);
                $body = $response->json();
            }

            if (! $response->successful() || ! is_array($body)) {
                return [
                    'success' => false,
                    'message' => PixApiErrorTypes::getMessageFromResponse(
                        is_array($body) ? $body : null,
                        'Erro ao consultar location na FYHUB.'
                    ),
                ];
            }

            return [
                'success' => true,
                'data' => $body,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Erro ao conectar com FYHUB: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array{success:bool,data?:array,message?:string}
     */
    public function detachTxidFromPayloadLocation(string|int $id): array
    {
        $locationId = trim((string) $id);
        if ($locationId === '') {
            return [
                'success' => false,
                'message' => 'Informe o id da location.',
            ];
        }

        $url = $this->baseUrl.'/loc/'.$locationId.'/txid';

        try {
            $response = SecureHttp::delete($url, [], $this->auth->authHeaders(), $this->timeout);
            $body = $response->json();

            if ($response->status() === 401) {
                $this->auth->invalidateToken();
                $response = SecureHttp::delete($url, [], $this->auth->authHeaders(), $this->timeout);
                $body = $response->json();
            }

            if (! $response->successful() || ! is_array($body)) {
                return [
                    'success' => false,
                    'message' => PixApiErrorTypes::getMessageFromResponse(
                        is_array($body) ? $body : null,
                        'Erro ao desvincular txid da location na FYHUB.'
                    ),
                ];
            }

            return [
                'success' => true,
                'data' => $body,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Erro ao conectar com FYHUB: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array{success:bool,data?:array,message?:string}
     */
    public function getPixByEndToEndId(string $e2eId): array
    {
        $e2e = trim($e2eId);
        if ($e2e === '') {
            return ['success' => false, 'message' => 'Informe o endToEndId.'];
        }

        $url = $this->baseUrl.'/pix/'.$e2e;

        try {
            $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout);
            $body = $response->json();

            if ($response->status() === 401) {
                $this->auth->invalidateToken();
                $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout);
                $body = $response->json();
            }

            if (! $response->successful() || ! is_array($body)) {
                return [
                    'success' => false,
                    'message' => PixApiErrorTypes::getMessageFromResponse(
                        is_array($body) ? $body : null,
                        'Erro ao consultar Pix na FYHUB.'
                    ),
                ];
            }

            return ['success' => true, 'data' => $body];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Erro ao conectar com FYHUB: '.$e->getMessage()];
        }
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array{success:bool,data?:array,message?:string}
     */
    public function listReceivedPix(string $inicioIso, string $fimIso, array $filters = []): array
    {
        $query = array_merge([
            'inicio' => $inicioIso,
            'fim' => $fimIso,
        ], $filters);

        $url = $this->baseUrl.'/pix';

        try {
            $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout, $query);
            $body = $response->json();

            if ($response->status() === 401) {
                $this->auth->invalidateToken();
                $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout, $query);
                $body = $response->json();
            }

            if (! $response->successful() || ! is_array($body)) {
                return [
                    'success' => false,
                    'message' => PixApiErrorTypes::getMessageFromResponse(
                        is_array($body) ? $body : null,
                        'Erro ao consultar lista de Pix na FYHUB.'
                    ),
                ];
            }

            return ['success' => true, 'data' => $body];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Erro ao conectar com FYHUB: '.$e->getMessage()];
        }
    }

    /**
     * @return array{success:bool,data?:array,message?:string}
     */
    public function getWebhookPix(?string $pixKey = null): array
    {
        $key = trim($pixKey ?? (string) config('fyhub.pix_key'));
        if ($key === '') {
            return ['success' => false, 'message' => 'Chave PIX FYHUB não configurada.'];
        }

        $url = $this->baseUrl.'/webhook/'.$key;

        try {
            $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout);
            $body = $response->json();

            if ($response->status() === 401) {
                $this->auth->invalidateToken();
                $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout);
                $body = $response->json();
            }

            if (! $response->successful() || ! is_array($body)) {
                return [
                    'success' => false,
                    'message' => PixApiErrorTypes::getMessageFromResponse(
                        is_array($body) ? $body : null,
                        'Erro ao consultar webhook Pix na FYHUB.'
                    ),
                ];
            }

            return ['success' => true, 'data' => $body];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Erro ao conectar com FYHUB: '.$e->getMessage()];
        }
    }

    /**
     * @return array{success:bool,data?:array,message?:string}
     */
    public function setWebhookPix(string $webhookUrl, ?string $pixKey = null): array
    {
        $key = trim($pixKey ?? (string) config('fyhub.pix_key'));
        if ($key === '') {
            return ['success' => false, 'message' => 'Chave PIX FYHUB não configurada.'];
        }

        $url = $this->baseUrl.'/webhook/'.$key;
        $payload = ['webhookUrl' => $webhookUrl];

        try {
            $response = SecureHttp::put($url, $payload, $this->auth->authHeaders(), $this->timeout);
            $body = $response->json();

            if ($response->status() === 401) {
                $this->auth->invalidateToken();
                $response = SecureHttp::put($url, $payload, $this->auth->authHeaders(), $this->timeout);
                $body = $response->json();
            }

            if (! $response->successful() || ! is_array($body)) {
                return [
                    'success' => false,
                    'message' => PixApiErrorTypes::getMessageFromResponse(
                        is_array($body) ? $body : null,
                        'Erro ao configurar webhook Pix na FYHUB.'
                    ),
                ];
            }

            return ['success' => true, 'data' => $body];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Erro ao conectar com FYHUB: '.$e->getMessage()];
        }
    }

    /**
     * @return array{success:bool,message?:string}
     */
    public function deleteWebhookPix(?string $pixKey = null): array
    {
        $key = trim($pixKey ?? (string) config('fyhub.pix_key'));
        if ($key === '') {
            return ['success' => false, 'message' => 'Chave PIX FYHUB não configurada.'];
        }

        $url = $this->baseUrl.'/webhook/'.$key;

        try {
            $response = SecureHttp::delete($url, [], $this->auth->authHeaders(), $this->timeout);
            $body = $response->json();

            if ($response->status() === 401) {
                $this->auth->invalidateToken();
                $response = SecureHttp::delete($url, [], $this->auth->authHeaders(), $this->timeout);
                $body = $response->json();
            }

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => PixApiErrorTypes::getMessageFromResponse(
                        is_array($body) ? $body : null,
                        'Erro ao remover webhook Pix na FYHUB.'
                    ),
                ];
            }

            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Erro ao conectar com FYHUB: '.$e->getMessage()];
        }
    }
}
