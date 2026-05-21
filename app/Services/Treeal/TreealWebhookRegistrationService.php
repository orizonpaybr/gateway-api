<?php

namespace App\Services\Treeal;

use App\Helpers\PixApiErrorTypes;
use App\Helpers\SecureHttp;
use Illuminate\Support\Facades\Log;

/**
 * Registro do webhook Pix na API TREEAL (PUT/GET/DELETE /webhook/{chave}).
 */
class TreealWebhookRegistrationService
{
    public function __construct(
        private readonly TreealAuthService $auth,
    ) {}

    public function resolveWebhookBaseUrl(): string
    {
        $configured = trim((string) config('treeal.webhook_base_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim((string) config('app.url', ''), '/').'/treeal/webhook';
    }

    /**
     * @return array{success: bool, message?: string, raw?: array}
     */
    public function configurePixWebhook(?string $pixKey = null): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'TREEAL não configurada para registrar webhook.',
            ];
        }

        $chave = trim($pixKey ?? (string) config('treeal.pix_key'));
        if ($chave === '') {
            return [
                'success' => false,
                'message' => 'Chave PIX TREEAL não configurada.',
            ];
        }

        $baseUrl = rtrim((string) config('treeal.base_url'), '/');
        $url = $baseUrl.'/webhook/'.rawurlencode($chave);
        $payload = [
            'webhookUrl' => $this->resolveWebhookBaseUrl(),
        ];

        return $this->request('PUT', $url, $payload, 'Erro ao configurar webhook Pix na TREEAL.');
    }

    /**
     * @return array{success: bool, message?: string, raw?: array}
     */
    public function getPixWebhook(?string $pixKey = null): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'TREEAL não configurada.',
            ];
        }

        $chave = trim($pixKey ?? (string) config('treeal.pix_key'));
        $baseUrl = rtrim((string) config('treeal.base_url'), '/');
        $url = $baseUrl.'/webhook/'.rawurlencode($chave);

        return $this->request('GET', $url, [], 'Erro ao consultar webhook Pix na TREEAL.');
    }

    /**
     * @return array{success: bool, message?: string}
     */
    public function deletePixWebhook(?string $pixKey = null): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'TREEAL não configurada.',
            ];
        }

        $chave = trim($pixKey ?? (string) config('treeal.pix_key'));
        $baseUrl = rtrim((string) config('treeal.base_url'), '/');
        $url = $baseUrl.'/webhook/'.rawurlencode($chave);
        $timeout = (int) config('treeal.timeout', 30);

        try {
            $response = SecureHttp::delete($url, [], $this->auth->authHeaders(), $timeout);

            if ($response->status() === 401) {
                $this->auth->invalidateToken();
                $response = SecureHttp::delete($url, [], $this->auth->authHeaders(), $timeout);
            }

            if ($response->status() === 204 || $response->successful()) {
                return ['success' => true];
            }

            $body = $response->json();
            $errorMsg = PixApiErrorTypes::getMessageFromResponse(
                is_array($body) ? $body : null,
                'Erro ao cancelar webhook Pix na TREEAL.'
            );

            return [
                'success' => false,
                'message' => $errorMsg,
            ];
        } catch (\Throwable $e) {
            Log::error('[TREEAL][WEBHOOK_REG] Falha ao cancelar webhook', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com TREEAL: '.$e->getMessage(),
            ];
        }
    }

    public function isConfigured(): bool
    {
        return ! empty(config('treeal.client_id'))
            && ! empty(config('treeal.client_secret'))
            && ! empty(config('treeal.base_url'))
            && TreealMtlsOptions::isConfigured();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, message?: string, raw?: array}
     */
    private function request(string $method, string $url, array $payload, string $defaultError): array
    {
        $timeout = (int) config('treeal.timeout', 30);

        try {
            if ($method === 'GET') {
                $response = SecureHttp::get($url, $this->auth->authHeaders(), $timeout);
            } else {
                $response = SecureHttp::put($url, $payload, $this->auth->authHeaders(), $timeout);
            }

            if ($response->status() === 401) {
                $this->auth->invalidateToken();
                if ($method === 'GET') {
                    $response = SecureHttp::get($url, $this->auth->authHeaders(), $timeout);
                } else {
                    $response = SecureHttp::put($url, $payload, $this->auth->authHeaders(), $timeout);
                }
            }

            $body = $response->json();

            if (! $response->successful() || ! is_array($body)) {
                $errorMsg = PixApiErrorTypes::getMessageFromResponse(
                    is_array($body) ? $body : null,
                    $defaultError
                );

                return [
                    'success' => false,
                    'message' => $errorMsg,
                    'raw' => is_array($body) ? $body : [],
                ];
            }

            return [
                'success' => true,
                'raw' => $body,
            ];
        } catch (\Throwable $e) {
            Log::error('[TREEAL][WEBHOOK_REG] Falha na requisição', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao conectar com TREEAL: '.$e->getMessage(),
            ];
        }
    }
}
