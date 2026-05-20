<?php

namespace App\Services\Treeal;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OAuth 2.0 Client Credentials da API QR Treeal (form-urlencoded + mTLS).
 */
class TreealAuthService
{
    private const CACHE_KEY = 'treeal_access_token';

    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $scope;
    private int $timeout;
    private int $tokenCacheBufferSeconds;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('treeal.base_url'), '/');
        $this->clientId = (string) config('treeal.client_id');
        $this->clientSecret = (string) config('treeal.client_secret');
        $this->scope = trim((string) config('treeal.scope', ''));
        $this->timeout = (int) config('treeal.timeout', 30);
        $this->tokenCacheBufferSeconds = (int) config('treeal.token_cache_buffer_seconds', 30);
    }

    public function getToken(): string
    {
        $token = Cache::get(self::CACHE_KEY);
        if (is_string($token) && $token !== '') {
            return $token;
        }

        return $this->requestNewToken();
    }

    public function invalidateToken(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, string>
     */
    public function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->getToken(),
            'Accept' => 'application/json',
        ];
    }

    private function requestNewToken(): string
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new \RuntimeException('Credenciais TREEAL (client_id / client_secret) não configuradas.');
        }

        $payload = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'client_credentials',
        ];

        if ($this->scope !== '') {
            $payload['scope'] = $this->scope;
        }

        $url = $this->baseUrl.'/oauth/token';

        try {
            $mtls = TreealMtlsOptions::build();
        } catch (\Throwable $e) {
            Log::error('[TREEAL][AUTH] Certificado mTLS não configurado', ['error' => $e->getMessage()]);
            throw $e;
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withOptions($mtls)
                ->asForm()
                ->acceptJson()
                ->post($url, $payload);

            $body = $response->json();

            if (! $response->successful() || ! is_array($body) || empty($body['access_token'])) {
                $errorMsg = is_array($body)
                    ? ($body['detail'] ?? $body['message'] ?? $body['error_description'] ?? 'Resposta inesperada da TREEAL')
                    : 'Resposta inesperada da TREEAL';

                Log::error('[TREEAL][AUTH] Falha ao obter token', [
                    'status' => $response->status(),
                    'error' => $errorMsg,
                ]);

                throw new \RuntimeException('TREEAL auth falhou: '.$errorMsg);
            }

            $expiresIn = max(1, (int) ($body['expires_in'] ?? 300));
            $cacheSeconds = max(1, $expiresIn - $this->tokenCacheBufferSeconds);
            Cache::put(self::CACHE_KEY, $body['access_token'], now()->addSeconds($cacheSeconds));

            Log::info('[TREEAL][AUTH] Token obtido com sucesso', [
                'expires_in' => $expiresIn,
                'cache_seconds' => $cacheSeconds,
            ]);

            return (string) $body['access_token'];
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[TREEAL][AUTH] Erro de conexão ao obter token', [
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Não foi possível conectar à API TREEAL: '.$e->getMessage(), 0, $e);
        }
    }
}
