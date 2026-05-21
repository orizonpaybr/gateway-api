<?php

namespace App\Services\Treeal;

use App\Helpers\PixApiErrorTypes;
use App\Helpers\SecureHttp;
use App\Services\PixAcquirer\PixAcquirerInterface;
use App\Services\TreealContas\TreealContasAuthService;
use Illuminate\Support\Facades\Log;

class TreealPixAcquirerService implements PixAcquirerInterface
{
    private const CASHOUT_STUB_MESSAGE = 'Treeal: operação não implementada — aguardando documentação';

    private string $baseUrl;
    private int $timeout;

    public function __construct(
        private readonly TreealAuthService $auth,
        private readonly TreealContasAuthService $contasAuth,
    ) {
        $this->baseUrl = rtrim((string) config('treeal.base_url'), '/');
        $this->timeout = (int) config('treeal.timeout', 30);
    }

    public function getReference(): string
    {
        return 'treeal';
    }

    public function isActive(): bool
    {
        return ! empty(config('treeal.client_id'))
            && ! empty(config('treeal.client_secret'))
            && ! empty(config('treeal.base_url'))
            && ! empty(config('treeal.pix_key'))
            && TreealMtlsOptions::isConfigured();
    }

    public function createCharge(
        float $amountReais,
        array $customer,
        ?string $correlationId = null,
        ?string $comment = null,
        ?string $expiresDate = null
    ): array {
        if (! $this->isActive()) {
            return [
                'success' => false,
                'message' => 'Adquirente TREEAL não configurada (credenciais, pix_key ou certificado mTLS).',
            ];
        }

        $txid = $this->normalizeTxid($correlationId);
        $debtorName = trim((string) ($customer['name'] ?? ''));
        $document = preg_replace('/\D/', '', (string) ($customer['document'] ?? ''));
        $payload = [
            'calendario' => [
                'expiracao' => (int) config('treeal.charge_expiration_seconds', 3600),
            ],
            'valor' => [
                'original' => number_format(round($amountReais, 2), 2, '.', ''),
                'modalidadeAlteracao' => (int) config('treeal.allow_amount_change', false) ? 1 : 0,
            ],
            'chave' => (string) config('treeal.pix_key'),
        ];

        $managedLocation = null;
        if ((bool) config('treeal.use_managed_locations', false)) {
            $managedLocation = $this->createPayloadLocation();
            if (! ($managedLocation['success'] ?? false)) {
                return [
                    'success' => false,
                    'message' => $managedLocation['message'] ?? 'Não foi possível criar location na TREEAL.',
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
                    'Erro ao criar cobrança imediata na TREEAL.'
                );

                Log::error('[TREEAL][CHARGE] Falha ao criar cobrança', [
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
            Log::error('[TREEAL][CHARGE] Exceção ao criar cobrança', [
                'txid' => $txid,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com TREEAL: '.$e->getMessage(),
            ];
        }
    }

    public function getChargeStatus(string $transactionId): array
    {
        if (! $this->isActive()) {
            return [
                'success' => false,
                'message' => 'Adquirente TREEAL não configurada.',
            ];
        }

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
                    'Erro ao consultar cobrança imediata na TREEAL.'
                );

                return [
                    'success' => false,
                    'message' => $errorMsg,
                ];
            }

            $providerStatus = strtoupper((string) ($body['status'] ?? 'ATIVA'));
            $endToEndId = '';
            $paidAt = null;
            if (isset($body['pix']) && is_array($body['pix'])) {
                $pixList = array_is_list($body['pix']) ? $body['pix'] : [$body['pix']];
                foreach ($pixList as $pix) {
                    if (! is_array($pix)) {
                        continue;
                    }
                    if ($endToEndId === '' && ! empty($pix['endToEndId'])) {
                        $endToEndId = trim((string) $pix['endToEndId']);
                    }
                    if ($paidAt === null && ! empty($pix['horario'])) {
                        $paidAt = $pix['horario'];
                    }
                }
            }

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
                    'endToEndId' => $endToEndId,
                    'horario' => $paidAt,
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('[TREEAL][CHARGE_STATUS] Exceção ao consultar cobrança', [
                'txid' => $txid,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com TREEAL: '.$e->getMessage(),
            ];
        }
    }

    public function mapChargeStatus(string $providerStatus): string
    {
        return match (strtoupper($providerStatus)) {
            'CONCLUIDA', 'LIQUIDATED' => 'PAID_OUT',
            'REMOVIDA_PELO_USUARIO_RECEBEDOR', 'REMOVIDA_PELO_PSP',
            'REMOVIDO_PELO_USUARIO_RECEBEDOR', 'REMOVIDO_PELO_PSP' => 'CANCELED',
            'ATIVA' => 'WAITING_FOR_APPROVAL',
            default => 'WAITING_FOR_APPROVAL',
        };
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
        return $this->cashOutStub();
    }

    public function mapPayoutStatus(string $providerStatus): string
    {
        $normalized = strtoupper(str_replace(' ', '_', trim($providerStatus)));

        return match ($normalized) {
            'LIQUIDATED' => 'COMPLETED',
            'CANCELED', 'CANCELLED' => 'CANCELLED',
            'REFUNDED', 'PARTIALLY_REFUNDED' => 'REFUNDED',
            'PROCESSING', 'ON_QUEUE', 'WAITING_CONFIRMATION', 'WAITING_SETTLEMENTCORE' => 'PROCESSING',
            default => 'PROCESSING',
        };
    }

    public function getPayoutStatus(string $transactionId, ?string $e2eId = null): array
    {
        return $this->cashOutStub();
    }

    public function createRefund(string $transactionId, float $amount, string $reason): array
    {
        if (! $this->isActive()) {
            return [
                'success' => false,
                'message' => 'Adquirente TREEAL não configurada.',
            ];
        }

        $e2eId = trim($transactionId);
        if ($e2eId === '') {
            return [
                'success' => false,
                'message' => 'EndToEndId é obrigatório para solicitar devolução na TREEAL.',
            ];
        }

        $refundId = strtolower(substr(bin2hex(random_bytes(6)), 0, 12));
        $url = $this->baseUrl.'/pix/'.$e2eId.'/devolucao/'.$refundId;
        $payload = [
            'valor' => number_format(round($amount, 2), 2, '.', ''),
            'natureza' => (string) config('treeal.refund_nature', 'ORIGINAL'),
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
                    'Erro ao solicitar devolução na TREEAL.'
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
            Log::error('[TREEAL][REFUND] Exceção ao solicitar devolução', [
                'e2eid' => $e2eId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com TREEAL: '.$e->getMessage(),
            ];
        }
    }

    /**
     * GET /pix/{e2eid} — consulta Pix recebido (útil para reconciliação complementar).
     *
     * @return array{success:bool,message?:string,raw?:array}
     */
    public function getPixByEndToEndId(string $endToEndId): array
    {
        if (! $this->isActive()) {
            return [
                'success' => false,
                'message' => 'Adquirente TREEAL não configurada.',
            ];
        }

        $e2eId = trim($endToEndId);
        if ($e2eId === '') {
            return [
                'success' => false,
                'message' => 'EndToEndId é obrigatório.',
            ];
        }

        $url = $this->baseUrl.'/pix/'.$e2eId;

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
                    'Erro ao consultar Pix na TREEAL.'
                );

                return [
                    'success' => false,
                    'message' => $errorMsg,
                ];
            }

            return [
                'success' => true,
                'raw' => $body,
            ];
        } catch (\Throwable $e) {
            Log::error('[TREEAL][PIX] Exceção ao consultar Pix', [
                'e2eid' => $e2eId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com TREEAL: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array{success:bool,id?:int,location?:string,tipoCob?:string,raw?:array,message?:string}
     */
    private function createPayloadLocation(string $tipoCob = 'cob'): array
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
                    'Erro ao criar location na TREEAL.'
                );

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
            return [
                'success' => false,
                'message' => 'Erro ao conectar com TREEAL: '.$e->getMessage(),
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

    /**
     * @return array{success: false, message: string}
     */
    private function cashOutStub(): array
    {
        return [
            'success' => false,
            'message' => self::CASHOUT_STUB_MESSAGE,
        ];
    }
}
