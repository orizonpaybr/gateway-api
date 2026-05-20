<?php

namespace App\Services\TreealContas;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OAuth 2.0 Client Credentials da API Contas Treeal (form-urlencoded + mTLS).
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
        $scope = trim((string) config('treeal_contas.scope', ''));

        if ($clientId === '' || $clientSecret === '' || $baseUrl === '') {
            throw new \RuntimeException('Credenciais TREEAL Contas (client_id / client_secret / base_url) não configuradas.');
        }

        $payload = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
        ];

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
                ->asForm()
                ->acceptJson()
                ->post($url, $payload);

            $body = $response->json();

            if (! $response->successful() || ! is_array($body) || empty($body['access_token'])) {
                $msg = is_array($body)
                    ? ($body['message'] ?? $body['detail'] ?? $body['error_description'] ?? 'Resposta inesperada')
                    : 'Resposta inesperada';

                Log::error('[TREEAL_CONTAS][AUTH] Falha ao obter token', [
                    'status' => $response->status(),
                    'error' => $msg,
                ]);

                throw new \RuntimeException('TREEAL Contas auth falhou: '.$msg);
            }

            $expiresIn = max(1, (int) ($body['expires_in'] ?? 300));
            $cacheSeconds = max(1, $expiresIn - $buffer);
            Cache::put(self::CACHE_KEY, (string) $body['access_token'], now()->addSeconds($cacheSeconds));

            Log::info('[TREEAL_CONTAS][AUTH] Token obtido', [
                'expires_in' => $expiresIn,
                'cache_seconds' => $cacheSeconds,
            ]);

            return (string) $body['access_token'];
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[TREEAL_CONTAS][AUTH] Erro de conexão', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Não foi possível conectar à API TREEAL Contas: '.$e->getMessage(), 0, $e);
        }
    }
}
