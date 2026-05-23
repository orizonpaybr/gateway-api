<?php

namespace App\Services\TreealContas;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OAuth 2.0 Client Credentials da API Contas Treeal / ONZ (JSON body + mTLS).
 *
 * Token independente do CashIn (API QR).
 */
class TreealContasAuthService
{
    private const CACHE_KEY = 'treeal_contas_access_token';

    public function getAccessToken(): string
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
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
            'Authorization' => 'Bearer '.$this->getAccessToken(),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    public function isConfigured(): bool
    {
        return trim((string) config('treeal_contas.client_id')) !== ''
            && trim((string) config('treeal_contas.client_secret')) !== ''
            && trim((string) config('treeal_contas.base_url')) !== ''
            && TreealContasMtlsOptions::isConfigured();
    }

    private function requestNewToken(): string
    {
        $clientId = trim((string) config('treeal_contas.client_id'));
        $clientSecret = (string) config('treeal_contas.client_secret');
        $baseUrl = rtrim((string) config('treeal_contas.base_url'), '/');
        $timeout = (int) config('treeal_contas.timeout', 30);
        $buffer = (int) config('treeal_contas.token_cache_buffer_seconds', 30);

        if ($clientId === '' || $clientSecret === '' || $baseUrl === '') {
            throw new \RuntimeException('Credenciais TREEAL Contas (client_id / client_secret / base_url) não configuradas.');
        }

        $payload = [
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'grantType' => 'client_credentials',
        ];
        $scope = trim((string) config('treeal_contas.scope', ''));
        if ($scope !== '') {
            $payload['scope'] = $scope;
        }

        $url = $baseUrl.'/oauth/token';

        try {
            $mtls = TreealContasMtlsOptions::build();
        } catch (\Throwable $e) {
            Log::error('[TREEAL_CONTAS][AUTH] Certificado mTLS não configurado', ['error' => $e->getMessage()]);
            throw $e;
        }

        try {
            $response = Http::timeout($timeout)
                ->withOptions($mtls)
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);

            $body = $response->json();
            $token = self::extractAccessToken(is_array($body) ? $body : null);

            if (! $response->successful() || $token === null) {
                $msg = is_array($body)
                    ? ($body['detail'] ?? $body['title'] ?? $body['message'] ?? 'Resposta inesperada')
                    : 'Resposta inesperada';

                Log::error('[TREEAL_CONTAS][AUTH] Falha ao obter token', [
                    'status' => $response->status(),
                    'error' => $msg,
                ]);

                throw new \RuntimeException('TREEAL Contas auth falhou: '.$msg);
            }

            $ttlSeconds = self::resolveTokenTtlSeconds(is_array($body) ? $body : [], $buffer);
            Cache::put(self::CACHE_KEY, $token, now()->addSeconds(max(60, $ttlSeconds)));

            Log::info('[TREEAL_CONTAS][AUTH] Token obtido', ['cache_seconds' => max(60, $ttlSeconds)]);

            return $token;
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[TREEAL_CONTAS][AUTH] Erro de conexão', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Não foi possível conectar à API TREEAL Contas: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private static function extractAccessToken(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }

        if (! empty($body['accessToken']) && is_string($body['accessToken'])) {
            return $body['accessToken'];
        }

        if (! empty($body['access_token']) && is_string($body['access_token'])) {
            return $body['access_token'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function resolveTokenTtlSeconds(array $body, int $buffer): int
    {
        if (isset($body['expiresAt']) && is_numeric($body['expiresAt']) && (float) $body['expiresAt'] > 0) {
            $ts = (float) $body['expiresAt'];
            if ($ts > 1e12) {
                $ts = $ts / 1000;
            }

            return max(120, (int) floor($ts - time()) - $buffer);
        }

        if (isset($body['expiresIn']) && is_numeric($body['expiresIn'])) {
            return max(120, (int) $body['expiresIn'] - $buffer);
        }

        if (isset($body['expires_in']) && is_numeric($body['expires_in'])) {
            return max(120, (int) $body['expires_in'] - $buffer);
        }

        return 3600 - min($buffer, 300);
    }
}
