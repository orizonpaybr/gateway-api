<?php

namespace App\Services\PixAcquirer;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Integração MagenPay na Coratri: PIX — QR dinâmico (depósito), Pix Out por chave (saque), estorno via API (dashboard).
 * Webhooks: \App\Jobs\ProcessMagenPayWebhookJob.
 */
class MagenPayService implements PixAcquirerInterface
{
    private const REFERENCE = 'magenpay';

    public function getReference(): string
    {
        return self::REFERENCE;
    }

    public function isActive(): bool
    {
        if (! config('magenpay.enabled')) {
            return false;
        }

        $publicId = trim((string) config('magenpay.public_key_id'));
        $pem = config('magenpay.private_key');
        if ($publicId === '' || $pem === null || trim($pem) === '') {
            return false;
        }

        $key = @openssl_pkey_get_private($pem);
        if ($key === false) {
            Log::warning('MagenPayService::isActive — chave privada PEM inválida');

            return false;
        }
        if (\PHP_VERSION_ID < 80000) {
            openssl_free_key($key);
        }

        return true;
    }

    /**
     * Requisição assinada (X-Signature, X-Timestamp, X-Nonce, X-Public-Key-ID).
     */
    private function signedRequest(string $method, string $absoluteUrl, string $body = ''): Response
    {
        $url = $this->assertAbsoluteUrl($absoluteUrl);
        $signedData = $this->buildSignedData(strtoupper($method), $url, $body);
        $signature = $this->signData($signedData);

        $mUpper = strtoupper($method);
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => (string) config('magenpay.user_agent'),
            'X-Signature' => $signature,
            'X-Timestamp' => $signedData['timestamp'],
            'X-Nonce' => $signedData['nonce'],
            'X-Public-Key-ID' => (string) config('magenpay.public_key_id'),
        ];
        if ($mUpper !== 'GET') {
            $headers['Content-Type'] = 'application/json';
        }

        $client = Http::withHeaders($headers)
            ->withOptions(['verify' => (bool) config('magenpay.verify_ssl')])
            ->timeout((int) config('magenpay.timeout'));

        return match ($mUpper) {
            'GET' => $client->get($url),
            'POST' => $client->withBody($body, 'application/json')->post($url),
            default => throw new \InvalidArgumentException("MagenPay: apenas GET ou POST são usados (recebido: {$mUpper})."),
        };
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
                'message' => 'Integração MagenPay inativa ou credenciais inválidas.',
            ];
        }

        $keyId = trim((string) config('magenpay.pix_key_id'));
        if ($keyId === '') {
            return [
                'success' => false,
                'message' => 'Configure MAGENPAY_PIX_KEY_ID (keyId da chave PIX no painel Magen).',
            ];
        }

        if ($amountReais <= 0) {
            return [
                'success' => false,
                'message' => 'Valor do depósito inválido.',
            ];
        }

        $txId = $this->buildConciliationTxId($correlationId);
        $payerName = (string) ($customer['name'] ?? 'Cliente');
        $payerTaxId = preg_replace('/\D/', '', (string) ($customer['document'] ?? ''));
        if (strlen($payerTaxId) < 11) {
            $payerTaxId = str_pad($payerTaxId, 11, '0', STR_PAD_LEFT);
        }
        if (strlen($payerTaxId) > 14) {
            $payerTaxId = substr($payerTaxId, 0, 14);
        }

        $expirationSeconds = max(60, (int) config('magenpay.qrcode_expiration_seconds', 86400));
        if ($expiresDate !== null && $expiresDate !== '') {
            try {
                $until = \Illuminate\Support\Carbon::parse($expiresDate);
                if ($until->isFuture()) {
                    $secs = $until->getTimestamp() - now()->getTimestamp();
                    $expirationSeconds = max(60, min(86400 * 30, $secs));
                }
            } catch (\Throwable) {
                // mantém padrão
            }
        }

        $payload = [
            'amount' => round($amountReais, 2),
            'amountFormat' => 'brl',
            'amountType' => 'fixed',
            'description' => $comment !== null && $comment !== '' ? $comment : 'Pagamento PIX',
            'expirationInSeconds' => $expirationSeconds,
            'keyId' => $keyId,
            'payerName' => $payerName,
            'payerTaxId' => $payerTaxId,
            'txId' => $txId,
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return ['success' => false, 'message' => 'Falha ao montar payload do QR Code.'];
        }

        try {
            $url = $this->qrcodeUrl('/api/v1/external/instant');
            $response = $this->signedRequest('POST', $url, $body);
        } catch (\Throwable $e) {
            Log::error('MagenPayService::createCharge — requisição', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao comunicar com a MagenPay: '.$e->getMessage(),
            ];
        }

        $json = $response->json();

        if (! $response->successful() || ! is_array($json)) {
            $msg = is_array($json) && isset($json['error'])
                ? (string) $json['error']
                : ($response->body() ?: 'Resposta inválida da MagenPay');

            Log::warning('MagenPayService::createCharge — falha HTTP', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'success' => false,
                'message' => $msg,
                'raw' => is_array($json) ? $json : null,
            ];
        }

        $brCode = isset($json['pixCopiaECola']) ? (string) $json['pixCopiaECola'] : null;
        if ($brCode === null || $brCode === '') {
            return [
                'success' => false,
                'message' => 'Resposta da MagenPay sem pixCopiaECola.',
                'raw' => $json,
            ];
        }

        $txIdResponse = isset($json['txId']) ? (string) $json['txId'] : $txId;

        return [
            'success' => true,
            'correlationID' => $txIdResponse,
            'brCode' => $brCode,
            'qrCodeImage' => null,
            'expiresDate' => $json['created_at'] ?? null,
            'status' => isset($json['status']) ? (string) $json['status'] : 'created',
            'raw' => $json,
        ];
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
        // API Magen Pix Out por chave: nome/documento/tipo não entram no body atual.
        if (! $this->isActive()) {
            return [
                'success' => false,
                'message' => 'Integração MagenPay inativa ou credenciais inválidas.',
            ];
        }

        $externalId = $correlationId !== null && trim($correlationId) !== ''
            ? preg_replace('/[^a-zA-Z0-9_-]/', '', $correlationId)
            : str_replace('-', '', Str::uuid()->toString());
        $externalId = substr($externalId, 0, 128);
        if ($externalId === '') {
            $externalId = str_replace('-', '', Str::uuid()->toString());
        }

        $externalId = trim($externalId);
        $receiverPixKey = trim($pixKey);
        if ($externalId === '' || $receiverPixKey === '') {
            return [
                'success' => false,
                'message' => 'externalId e chave PIX são obrigatórios.',
            ];
        }

        $amountErr = $this->validatePixOutAmount($amountReais);
        if ($amountErr !== null) {
            return $amountErr;
        }

        $payload = [
            'externalId' => $externalId,
            'receiverPixKey' => $receiverPixKey,
            'amount' => round($amountReais, 2),
            'amountFormat' => 'brl',
        ];
        if ($description !== null && $description !== '') {
            $payload['description'] = $description;
        }

        return $this->postPixOutRequest('/requests/out', $payload, $externalId);
    }

    /**
     * POST /pix/.../requests/in/{endToEndId}/reversals — estorno de Pix recebido (cash-in).
     * Uso previsto: fluxo de estorno no dashboard Coratri.
     *
     * @param  string  $reason  customerRequest | bankError | fraud | cashierError
     * @return array{success:bool,message?:string,referenceCode?:string,status?:string,raw?:array}
     */
    public function createPixReversal(string $endToEndId, string $externalId, string $reason): array
    {
        if (! $this->isActive()) {
            return [
                'success' => false,
                'message' => 'Integração MagenPay inativa ou credenciais inválidas.',
            ];
        }

        $endToEndId = trim($endToEndId);
        $externalId = trim($externalId);
        if ($endToEndId === '' || $externalId === '') {
            return [
                'success' => false,
                'message' => 'endToEndId e externalId são obrigatórios.',
            ];
        }

        $allowedReasons = ['customerRequest', 'bankError', 'fraud', 'cashierError'];
        if (! in_array($reason, $allowedReasons, true)) {
            return [
                'success' => false,
                'message' => 'reason deve ser um de: customerRequest, bankError, fraud, cashierError.',
            ];
        }

        $payload = [
            'externalId' => $externalId,
            'reason' => $reason,
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return ['success' => false, 'message' => 'Falha ao montar payload do estorno PIX.'];
        }

        $path = '/requests/in/'.rawurlencode($endToEndId).'/reversals';

        try {
            $url = $this->pixApiUrl($path);
            $response = $this->signedRequest('POST', $url, $body);
        } catch (\Throwable $e) {
            Log::error('MagenPayService::createPixReversal', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao comunicar com a MagenPay: '.$e->getMessage(),
            ];
        }

        $json = $response->json();

        if (! $response->successful() || ! is_array($json)) {
            return $this->pixApiErrorResult($response, $json);
        }

        $ref = $json['returnId'] ?? $json['externalId'] ?? $externalId;

        return [
            'success' => true,
            'referenceCode' => is_scalar($ref) ? (string) $ref : $externalId,
            'status' => isset($json['status']) ? (string) $json['status'] : 'processing',
            'raw' => $json,
        ];
    }

    public function mapPayoutStatus(string $providerStatus): string
    {
        $s = strtolower(trim($providerStatus));

        return match (true) {
            in_array($s, ['paid', 'completed', 'success', 'done', 'confirmed'], true) => 'COMPLETED',
            in_array($s, ['failed', 'error', 'rejected', 'cancelled', 'canceled'], true) => 'FAILED',
            in_array($s, ['processing', 'pending', 'waiting', 'queued', 'sent'], true) => 'PENDING',
            default => 'PENDING',
        };
    }

    /**
     * @return array{success:false,message:string}|null
     */
    private function validatePixOutAmount(float $amountReais): ?array
    {
        $maxOut = (float) config('magenpay.pix_max_out_amount', 15000);
        if ($amountReais < 0.01 || $amountReais > $maxOut) {
            return [
                'success' => false,
                'message' => 'Valor do Pix Out deve estar entre R$ 0,01 e R$ '.number_format($maxOut, 2, ',', '.').'.',
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success:bool,message?:string,referenceCode?:string,status?:string,raw?:array}
     */
    private function postPixOutRequest(string $path, array $payload, string $externalId): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return ['success' => false, 'message' => 'Falha ao montar payload do Pix Out.'];
        }

        try {
            $url = $this->pixApiUrl($path);
            $response = $this->signedRequest('POST', $url, $body);
        } catch (\Throwable $e) {
            Log::error('MagenPayService::postPixOutRequest', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao comunicar com a MagenPay: '.$e->getMessage(),
            ];
        }

        $json = $response->json();

        if (! $response->successful() || ! is_array($json)) {
            return $this->pixApiErrorResult($response, $json);
        }

        $ref = $json['txId'] ?? $json['endToEndId'] ?? $externalId;

        return [
            'success' => true,
            'referenceCode' => is_scalar($ref) ? (string) $ref : $externalId,
            'status' => isset($json['status']) ? (string) $json['status'] : 'processing',
            'raw' => $json,
        ];
    }

    /**
     * @param  mixed  $json
     * @return array{success:false,message:string,raw?:array|null}
     */
    private function pixApiErrorResult(Response $response, $json): array
    {
        if (is_array($json) && isset($json['message'])) {
            $msg = (string) $json['message'];
        } elseif (is_array($json) && isset($json['error'])) {
            $msg = (string) $json['error'];
        } else {
            $msg = $response->body() ?: 'Resposta inválida da MagenPay';
        }

        Log::warning('MagenPayService — falha Pix API', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return [
            'success' => false,
            'message' => $msg,
            'raw' => is_array($json) ? $json : null,
        ];
    }

    private function qrcodeUrl(string $path): string
    {
        $base = rtrim((string) config('magenpay.qrcode_base_url'), '/');
        $path = str_starts_with($path, '/') ? $path : '/'.$path;

        return $base.$path;
    }

    private function pixApiUrl(string $path): string
    {
        $base = rtrim((string) config('magenpay.pix_api_base_url'), '/');
        $path = str_starts_with($path, '/') ? $path : '/'.$path;

        return $base.$path;
    }

    private function assertAbsoluteUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        throw new \InvalidArgumentException('MagenPay: URL da requisição deve ser absoluta (qrcode / pix API).');
    }

    /**
     * txId de conciliação (26–35 caracteres alfanuméricos na doc Magen; aqui 32 hex).
     */
    private function buildConciliationTxId(?string $correlationId): string
    {
        $seed = $correlationId !== null && $correlationId !== ''
            ? $correlationId
            : Str::uuid()->toString();

        $hex = bin2hex(hash('sha256', $seed, true));

        return substr($hex, 0, 32);
    }

    /**
     * @return array{method:string,path:string,query:string,body:string,timestamp:string,nonce:string}
     */
    private function buildSignedData(string $method, string $url, string $body): array
    {
        $parsed = parse_url($url);

        return [
            'method' => strtoupper($method),
            'path' => $parsed['path'] ?? '',
            'query' => $parsed['query'] ?? '',
            'body' => $body ?? '',
            'timestamp' => $this->getIsoTimestamp(),
            'nonce' => $this->generateNonce(),
        ];
    }

    private function generateNonce(int $length = 16): string
    {
        $bytes = random_bytes($length);

        return 'random-nonce'.rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function getIsoTimestamp(): string
    {
        $micro = microtime(true);
        $milliseconds = sprintf('%03d', ($micro - floor($micro)) * 1000);

        return gmdate('Y-m-d\\TH:i:s', (int) $micro).'.'.$milliseconds.'Z';
    }

    /**
     * @param  array{method:string,path:string,query:string,body:string,timestamp:string,nonce:string}  $signedData
     */
    private function signData(array $signedData): string
    {
        $dataStr = json_encode($signedData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($dataStr === false) {
            throw new \RuntimeException('MagenPay: falha ao serializar signed data');
        }

        $pem = (string) config('magenpay.private_key');
        $privateKey = openssl_pkey_get_private($pem);
        if (! $privateKey) {
            throw new \RuntimeException('MagenPay: chave privada inválida para assinatura');
        }

        $signature = '';
        $ok = openssl_sign($dataStr, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (\PHP_VERSION_ID < 80000) {
            openssl_free_key($privateKey);
        }

        if (! $ok) {
            throw new \RuntimeException('MagenPay: openssl_sign falhou');
        }

        return base64_encode($signature);
    }
}
