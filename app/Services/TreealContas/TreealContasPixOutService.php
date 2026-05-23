<?php

namespace App\Services\TreealContas;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Pix cash-out na API Contas Treeal / ONZ (mTLS + OAuth corporativo).
 */
class TreealContasPixOutService
{
    public function __construct(private readonly TreealContasApiClient $client) {}

    public function isConfigured(): bool
    {
        try {
            TreealContasMtlsOptions::build();
        } catch (\Throwable) {
            return false;
        }

        return filled(config('treeal_contas.client_id'))
            && filled(config('treeal_contas.client_secret'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{success:bool,data?:array|null,message?:string,status?:int}
     */
    public function initiatePaymentByDict(string $idempotencyKey, array $body, array $overrides = []): array
    {
        $payload = array_merge($body, $overrides);
        $response = $this->client->postJson('/pix/payments/dict', $payload, [
            'x-idempotency-key' => $this->normalizeIdempotencyKey($idempotencyKey),
        ]);

        return $this->decodePaymentInitiationResponse($response);
    }

    /**
     * @return array{success:bool,data?:array|null,message?:string,status?:int}
     */
    public function getPaymentByEndToEndId(string $endToEndId): array
    {
        $e2e = trim($endToEndId);
        if ($e2e === '') {
            return ['success' => false, 'message' => 'endToEndId obrigatório.'];
        }

        $response = $this->client->get('/pix/payments/'.rawurlencode($e2e));

        return $this->decodePaymentDetailResponse($response);
    }

    /**
     * @return array{success:bool,data?:array|null,message?:string,status?:int}
     */
    public function getPaymentByIdempotencyKey(string $idempotencyKey): array
    {
        $key = trim($idempotencyKey);
        if ($key === '') {
            return ['success' => false, 'message' => 'idempotencyKey obrigatória.'];
        }

        $response = $this->client->get('/pix/payments/idempotencyKey/'.rawurlencode($key));

        return $this->decodePaymentDetailResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDictPaymentBody(
        string $pixKeyFormatted,
        float $amountReais,
        ?string $description = null,
        ?string $creditorDocument = null
    ): array {
        $body = [
            'pixKey' => $pixKeyFormatted,
            'priority' => (string) config('treeal_contas.payout_priority', 'NORM'),
            'paymentFlow' => (string) config('treeal_contas.payout_payment_flow', 'INSTANT'),
            'expiration' => (int) config('treeal_contas.payout_expiration_seconds', 600),
            'payment' => [
                'currency' => 'BRL',
                'amount' => round($amountReais, 2),
            ],
        ];

        if ($description !== null && trim($description) !== '') {
            $body['description'] = mb_substr(trim($description), 0, 140);
        }

        if ($creditorDocument !== null && preg_replace('/\D/', '', $creditorDocument) !== '') {
            $body['creditorDocument'] = preg_replace('/\D/', '', $creditorDocument);
            if ((string) config('treeal_contas.payout_priority_when_document', 'NORM') === 'HIGH') {
                $body['priority'] = 'HIGH';
            }
        }

        return $body;
    }

    public function formatPixKeyForDict(string $pixKey, string $pixKeyType): string
    {
        $key = trim($pixKey);
        $type = strtolower(trim($pixKeyType));

        return match ($type) {
            'cpf', 'cnpj' => preg_replace('/\D/', '', $key),
            'email' => strtolower($key),
            'phone', 'telefone', 'celular' => $this->formatPhonePixKey($key),
            'random', 'aleatoria', 'evp' => strtolower($key),
            default => $key,
        };
    }

    private function formatPhonePixKey(string $key): string
    {
        $digits = preg_replace('/\D/', '', $key);
        if ($digits === '') {
            return trim($key);
        }

        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            return '+'.$digits;
        }

        if (strlen($digits) >= 10 && strlen($digits) <= 11) {
            return '+55'.$digits;
        }

        return '+'.$digits;
    }

    private function normalizeIdempotencyKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return (string) Str::uuid();
        }

        if (strlen($key) > 128) {
            return substr($key, 0, 128);
        }

        return $key;
    }

    /**
     * @return array{success:bool,data?:array|null,message?:string,status?:int}
     */
    private function decodePaymentInitiationResponse(Response $response): array
    {
        $status = $response->status();
        $body = $response->json();

        if (($response->successful() || $status === 202) && is_array($body)) {
            return ['success' => true, 'data' => $body, 'status' => $status];
        }

        return [
            'success' => false,
            'message' => self::messageFromBody(is_array($body) ? $body : null),
            'data' => is_array($body) ? $body : null,
            'status' => $status,
        ];
    }

    /**
     * @return array{success:bool,data?:array|null,message?:string,status?:int}
     */
    private function decodePaymentDetailResponse(Response $response): array
    {
        $status = $response->status();
        $body = $response->json();

        if ($response->successful() && is_array($body)) {
            return ['success' => true, 'data' => $body, 'status' => $status];
        }

        return [
            'success' => false,
            'message' => self::messageFromBody(is_array($body) ? $body : null),
            'data' => is_array($body) ? $body : null,
            'status' => $status,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private static function messageFromBody(?array $body): string
    {
        if ($body === null) {
            return 'Erro na API TREEAL Contas (Pix pagamentos).';
        }

        $detail = isset($body['detail']) && is_string($body['detail']) ? trim($body['detail']) : '';
        if ($detail !== '') {
            return $detail;
        }
        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
        if ($title !== '') {
            return $title;
        }
        $message = isset($body['message']) && is_string($body['message']) ? trim($body['message']) : '';

        return $message !== '' ? $message : 'Erro na API TREEAL Contas (Pix pagamentos).';
    }
}
