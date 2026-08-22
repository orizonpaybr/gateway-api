<?php

namespace App\Services\Simpay;

use App\Helpers\SecureHttp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SimpayAuthService
{
    private const CACHE_KEY = 'simpay_access_token';

    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private int $timeout;
    private int $cacheMinutes;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('simpay.base_url'), '/');
        $this->clientId = (string) config('simpay.client_id');
        $this->clientSecret = (string) config('simpay.client_secret');
        $this->timeout = (int) config('simpay.timeout', 30);
        $this->cacheMinutes = (int) config('simpay.token_cache_minutes', 55);
    }

    /**
     * Retorna um access_token válido, buscando do cache ou solicitando um novo.
     *
     * @throws \RuntimeException Quando não é possível obter o token.
     */
    public function getToken(): string
    {
        $token = Cache::get(self::CACHE_KEY);

        if ($token !== null) {
            return $token;
        }

        return $this->requestNewToken();
    }

    /**
     * Invalida o token em cache, forçando uma nova autenticação na próxima chamada.
     */
    public function invalidateToken(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Solicita um novo token à API SIMPAY e armazena em cache.
     */
    private function requestNewToken(): string
    {
        if (empty($this->clientId) || empty($this->clientSecret)) {
            throw new \RuntimeException('Credenciais SIMPAY (client_id / client_secret) não configuradas.');
        }

        $url = $this->baseUrl.'/auth-token/';

        try {
            $response = SecureHttp::post($url, [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ], [], $this->timeout);

            $rawBody = $response->body();
            $body = $response->json();
            $jsonError = json_last_error();

            if (!$response->successful() || empty($body['worked']) || empty($body['access_token'])) {
                $detail = is_array($body)
                    ? ($body['detail'] ?? $body['message'] ?? null)
                    : null;
                $detail = $detail ?? 'Resposta inesperada';

                Log::error('[SIMPAY] Falha ao obter token', [
                    'url' => $url,
                    'status' => $response->status(),
                    'detail' => $detail,
                    'client_id_prefix' => strlen($this->clientId) > 0
                        ? substr($this->clientId, 0, 8).'…'
                        : '(vazio)',
                    'response_json_ok' => is_array($body),
                    'json_decode_error' => $jsonError !== JSON_ERROR_NONE ? json_last_error_msg() : null,
                    'body_parsed' => is_array($body) ? $body : null,
                    'body_raw_truncated' => strlen($rawBody) > 2000 ? substr($rawBody, 0, 2000).'…(truncado)' : $rawBody,
                ]);

                throw new \RuntimeException("SIMPAY auth falhou: {$detail}");
            }

            $accessToken = $body['access_token'];

            Cache::put(self::CACHE_KEY, $accessToken, now()->addMinutes($this->cacheMinutes));

            Log::info('[SIMPAY] Token obtido com sucesso', [
                'expires_in_minutes' => $this->cacheMinutes,
            ]);

            return $accessToken;

        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[SIMPAY] Erro de conexão ao obter token', [
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Não foi possível conectar à API SIMPAY: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Retorna os headers de autorização prontos para uso em chamadas subsequentes.
     *
     * @return array<string, string>
     */
    public function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->getToken(),
            'Accept' => 'application/json',
        ];
    }
}
