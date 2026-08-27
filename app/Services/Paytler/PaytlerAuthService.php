<?php

namespace App\Services\Paytler;

use App\Helpers\SecureHttp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Autenticação Paytler: troca client_id/client_secret por um Bearer JWT.
 * POST {base}/auth/token -> { access_token, expires_in, token_type }.
 * Sem HMAC (diferente da Simpay).
 */
class PaytlerAuthService
{
    private const CACHE_KEY = 'paytler_access_token';

    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private int $timeout;
    private int $cacheMinutes;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('paytler.base_url'), '/');
        $this->clientId = (string) config('paytler.client_id');
        $this->clientSecret = (string) config('paytler.client_secret');
        $this->timeout = (int) config('paytler.timeout', 30);
        $this->cacheMinutes = (int) config('paytler.token_cache_minutes', 55);
    }

    public function getToken(): string
    {
        $token = Cache::get(self::CACHE_KEY);
        if ($token !== null) {
            return $token;
        }

        return $this->requestNewToken();
    }

    public function invalidateToken(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function requestNewToken(): string
    {
        if (empty($this->clientId) || empty($this->clientSecret)) {
            throw new \RuntimeException('Credenciais PAYTLER (client_id / client_secret) não configuradas.');
        }

        $url = $this->baseUrl.'/auth/token';

        try {
            $response = SecureHttp::post($url, [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ], [], $this->timeout);

            $body = $response->json();

            if (! $response->successful() || empty($body['access_token'])) {
                $detail = is_array($body)
                    ? ($body['message'] ?? $body['error'] ?? 'Resposta inesperada')
                    : 'Resposta inesperada';

                Log::error('[PAYTLER] Falha ao obter token', [
                    'url' => $url,
                    'status' => $response->status(),
                    'detail' => $detail,
                    'client_id_prefix' => $this->clientId !== '' ? substr($this->clientId, 0, 8).'…' : '(vazio)',
                ]);

                throw new \RuntimeException("PAYTLER auth falhou: {$detail}");
            }

            $accessToken = (string) $body['access_token'];

            // Cacheia pelo menor entre (expires_in - 60s) e o teto de config.
            $expiresIn = (int) ($body['expires_in'] ?? 0);
            $ttlSeconds = $expiresIn > 60
                ? min($expiresIn - 60, $this->cacheMinutes * 60)
                : $this->cacheMinutes * 60;

            Cache::put(self::CACHE_KEY, $accessToken, now()->addSeconds($ttlSeconds));

            Log::info('[PAYTLER] Token obtido', ['ttl_seconds' => $ttlSeconds]);

            return $accessToken;
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[PAYTLER] Erro de conexão ao obter token', ['error' => $e->getMessage()]);

            throw new \RuntimeException('Não foi possível conectar à API PAYTLER: '.$e->getMessage(), 0, $e);
        }
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
}
