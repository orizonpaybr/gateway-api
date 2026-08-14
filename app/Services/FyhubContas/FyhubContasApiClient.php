<?php

namespace App\Services\FyhubContas;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP para API Contas Fyhub (mTLS + Bearer em todas as chamadas após o token).
 */
class FyhubContasApiClient
{
    public function __construct(private readonly FyhubContasAuthService $auth) {}

    /**
     * @param  array<string, string|int|float|bool|null>  $query
     * @param  array<string, string>  $extraHeaders
     */
    public function get(string $path, array $query = [], array $extraHeaders = []): Response
    {
        return $this->request('GET', $path, ['query' => $query, 'headers' => $extraHeaders]);
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, string>  $extraHeaders
     */
    public function postJson(string $path, array $json = [], array $extraHeaders = []): Response
    {
        return $this->request('POST', $path, ['json' => $json, 'headers' => $extraHeaders]);
    }

    /**
     * @param  array{query?: array<string, mixed>, json?: array<string, mixed>, headers?: array<string, string>}  $options
     */
    private function request(string $method, string $path, array $options = []): Response
    {
        $baseUrl = rtrim((string) config('fyhub_contas.base_url'), '/');
        $timeout = (int) config('fyhub_contas.timeout', 30);
        $path = '/'.ltrim($path, '/');
        $url = $baseUrl.$path;

        $mtls = FyhubContasMtlsOptions::build();

        $extra = $options['headers'] ?? [];
        $headers = array_merge($this->auth->authHeaders(), $extra);

        try {
            $client = Http::timeout($timeout)
                ->withOptions($mtls)
                ->withHeaders($headers);

            if ($method === 'GET') {
                $query = $options['query'] ?? [];
                $response = $client->get($url, $query);
            } elseif ($method === 'POST') {
                $json = $options['json'] ?? [];
                $response = $client->asJson()->post($url, $json);
            } else {
                throw new \InvalidArgumentException('Método não suportado: '.$method);
            }

            if ($response->status() === 401) {
                $this->auth->invalidateToken();
                $headers = array_merge($this->auth->authHeaders(), $extra);
                $client = Http::timeout($timeout)
                    ->withOptions($mtls)
                    ->withHeaders($headers);

                if ($method === 'GET') {
                    $response = $client->get($url, $options['query'] ?? []);
                } else {
                    $response = $client->asJson()->post($url, $options['json'] ?? []);
                }
            }

            return $response;
        } catch (\Throwable $e) {
            Log::error('[FYHUB_CONTAS][HTTP] Falha na requisição', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
