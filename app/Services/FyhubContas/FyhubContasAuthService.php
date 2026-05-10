<?php

namespace App\Services\FyhubContas;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OAuth 2.0 Client Credentials da API Contas Fyhub (JSON body + mTLS).
 */
class FyhubContasAuthService
{
    private const CACHE_KEY = 'fyhub_contas_access_token';

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

    private function requestNewToken(): string
    {
        $clientId = trim((string) config('fyhub_contas.client_id'));
        $clientSecret = (string) config('fyhub_contas.client_secret');
        $baseUrl = rtrim((string) config('fyhub_contas.base_url'), '/');
        $timeout = (int) config('fyhub_contas.timeout', 30);
        $buffer = (int) config('fyhub_contas.token_cache_buffer_seconds', 30);

        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException('Credenciais FYHUB Contas (client_id / client_secret) não configuradas.');
        }

        $payload = [
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'grantType' => 'client_credentials',
        ];
        $scope = trim((string) config('fyhub_contas.scope', ''));
        if ($scope !== '') {
            $payload['scope'] = $scope;
        }

        $url = $baseUrl.'/oauth/token';

        try {
            $mtls = FyhubContasMtlsOptions::build();
        } catch (\Throwable $e) {
            Log::error('[FYHUB_CONTAS][AUTH] Certificado mTLS não configurado', ['error' => $e->getMessage()]);
            throw $e;
        }

        try {
            $response = Http::timeout($timeout)
                ->withOptions($mtls)
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);

            $body = $response->json();

            if (! $response->successful() || ! is_array($body) || empty($body['accessToken'])) {
                $msg = is_array($body)
                    ? ($body['message'] ?? $body['detail'] ?? $body['title'] ?? 'Resposta inesperada')
                    : 'Resposta inesperada';

                Log::error('[FYHUB_CONTAS][AUTH] Falha ao obter token', [
                    'status' => $response->status(),
                    'error' => $msg,
                ]);

                throw new \RuntimeException('FYHUB Contas auth falhou: '.$msg);
            }

            $token = (string) $body['accessToken'];
            $ttlSeconds = self::resolveTokenTtlSeconds($body, $buffer);
            Cache::put(self::CACHE_KEY, $token, now()->addSeconds(max(60, $ttlSeconds)));

            Log::info('[FYHUB_CONTAS][AUTH] Token obtido', ['cache_seconds' => max(60, $ttlSeconds)]);

            return $token;
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[FYHUB_CONTAS][AUTH] Erro de conexão', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Não foi possível conectar à API FYHUB Contas: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function resolveTokenTtlSeconds(array $body, int $buffer): int
    {
        // Documentação exemplo: expiresAt (pode ser epoch segundos ou ms).
        if (isset($body['expiresAt'])) {
            $exp = $body['expiresAt'];
            if (is_numeric($exp) && (float) $exp > 0) {
                $ts = (float) $exp;
                if ($ts > 1e12) {
                    $ts = $ts / 1000;
                }
                $ttl = (int) floor($ts - time()) - $buffer;

                return max(120, $ttl);
            }
        }

        if (isset($body['expiresIn']) && is_numeric($body['expiresIn'])) {
            return max(120, (int) $body['expiresIn'] - $buffer);
        }

        return 3600 - min($buffer, 300);
    }
}
