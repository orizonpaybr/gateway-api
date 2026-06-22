<?php

namespace App\Services\TreealContas;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP para API Contas Treeal (mTLS + Bearer em todas as chamadas após o token).
 */
class TreealContasApiClient
{
    public function __construct(private readonly TreealContasAuthService $auth) {}

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
     * @param  array<string, string>  $extraHeaders
     */
    public function delete(string $path, array $extraHeaders = []): Response
    {
        return $this->request('DELETE', $path, ['headers' => $extraHeaders]);
    }

    /**
     * Envio multipart/form-data (ex.: defesa de infração com anexos).
     *
     * @param  array<string, scalar>  $fields
     * @param  array<int, array{name: string, contents: string, filename: string}>  $files
     */
    public function postMultipart(string $path, array $fields = [], array $files = []): Response
    {
        $baseUrl = rtrim((string) config('treeal_contas.base_url'), '/');
        $timeout = (int) config('treeal_contas.timeout', 30);
        $path = '/'.ltrim($path, '/');
        $url = $baseUrl.$path;

        $mtls = TreealContasMtlsOptions::build();

        $send = function () use ($timeout, $mtls, $files, $url, $fields): Response {
            // Multipart não deve forçar Content-Type application/json.
            $headers = $this->auth->authHeaders();
            unset($headers['Content-Type']);

            $client = Http::timeout($timeout)
                ->withOptions($mtls)
                ->withHeaders($headers)
                ->asMultipart();

            foreach ($files as $file) {
                $client->attach($file['name'], $file['contents'], $file['filename']);
            }

            return $client->post($url, $fields);
        };

        try {
            $response = $send();

            if ($response->status() === 401) {
                $this->auth->invalidateToken();
                $response = $send();
            }

            return $response;
        } catch (\Throwable $e) {
            Log::error('[TREEAL_CONTAS][HTTP] Falha em requisição multipart', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @param  array{query?: array<string, mixed>, json?: array<string, mixed>, headers?: array<string, string>}  $options
     */
    private function request(string $method, string $path, array $options = []): Response
    {
        $baseUrl = rtrim((string) config('treeal_contas.base_url'), '/');
        $timeout = (int) config('treeal_contas.timeout', 30);
        $path = '/'.ltrim($path, '/');
        $url = $baseUrl.$path;

        $mtls = TreealContasMtlsOptions::build();

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
            } elseif ($method === 'DELETE') {
                $response = $client->delete($url);
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
                } elseif ($method === 'DELETE') {
                    $response = $client->delete($url);
                } else {
                    $response = $client->asJson()->post($url, $options['json'] ?? []);
                }
            }

            return $response;
        } catch (\Throwable $e) {
            Log::error('[TREEAL_CONTAS][HTTP] Falha na requisição', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
