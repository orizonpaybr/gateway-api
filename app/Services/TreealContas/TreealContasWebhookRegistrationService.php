<?php

namespace App\Services\TreealContas;

use Illuminate\Support\Facades\Log;

/**
 * Registro de webhooks na API Contas TREEAL / ONZ (POST /webhooks/{type}).
 */
class TreealContasWebhookRegistrationService
{
    /** @var array<int, string> */
    public const REGISTERABLE_TYPES = ['TRANSFER', 'RECEIVE', 'REFUND', 'CASHOUT', 'INFRACTION'];

    public function __construct(
        private readonly TreealContasApiClient $client,
        private readonly TreealContasAuthService $auth,
    ) {}

    public function resolveWebhookUri(): string
    {
        $configured = trim((string) config('treeal_contas.webhook_base_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim((string) config('app.url', ''), '/').'/treeal/contas/webhook';
    }

    /**
     * @return array{success: bool, message?: string, raw?: array}
     */
    public function listWebhooks(): array
    {
        if (! $this->auth->isConfigured()) {
            return ['success' => false, 'message' => 'TREEAL Contas não configurada.'];
        }

        try {
            $response = $this->client->get('/webhooks');
            $body = $response->json();

            if (! $response->successful() || ! is_array($body)) {
                return [
                    'success' => false,
                    'message' => self::messageFromBody(is_array($body) ? $body : null),
                ];
            }

            return ['success' => true, 'raw' => $body];
        } catch (\Throwable $e) {
            Log::error('[TREEAL_CONTAS][WEBHOOK_REG] Falha ao listar webhooks', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, message?: string, raw?: array}
     */
    public function registerWebhook(string $type): array
    {
        if (! $this->auth->isConfigured()) {
            return ['success' => false, 'message' => 'TREEAL Contas não configurada.'];
        }

        $normalized = strtoupper(trim($type));
        $pathType = strtolower($normalized);
        if (! in_array($normalized, self::REGISTERABLE_TYPES, true)) {
            return ['success' => false, 'message' => 'Tipo de webhook inválido: '.$type];
        }

        $payload = $this->buildRegistrationPayload();

        try {
            $response = $this->client->postJson('/webhooks/'.$pathType, $payload);
            $body = $response->json();

            if (! $response->successful() || ! is_array($body)) {
                return [
                    'success' => false,
                    'message' => self::messageFromBody(is_array($body) ? $body : null),
                    'raw' => is_array($body) ? $body : [],
                ];
            }

            return ['success' => true, 'raw' => $body];
        } catch (\Throwable $e) {
            Log::error('[TREEAL_CONTAS][WEBHOOK_REG] Falha ao registrar webhook', [
                'type' => $normalized,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, registered: array<int, string>, failed: array<string, string>}
     */
    public function registerAll(): array
    {
        $registered = [];
        $failed = [];

        foreach (self::REGISTERABLE_TYPES as $type) {
            $result = $this->registerWebhook($type);
            if ($result['success'] ?? false) {
                $registered[] = $type;
            } else {
                $failed[$type] = (string) ($result['message'] ?? 'Erro desconhecido');
            }
        }

        return [
            'success' => $failed === [],
            'registered' => $registered,
            'failed' => $failed,
        ];
    }

    /**
     * @return array{success: bool, message?: string}
     */
    public function deleteWebhook(string $webhookId): array
    {
        $id = trim($webhookId);
        if ($id === '') {
            return ['success' => false, 'message' => 'webhookId obrigatório.'];
        }

        try {
            $response = $this->client->delete('/webhooks/'.rawurlencode($id));

            if ($response->status() === 204 || $response->successful()) {
                return ['success' => true];
            }

            $body = $response->json();

            return [
                'success' => false,
                'message' => self::messageFromBody(is_array($body) ? $body : null),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRegistrationPayload(): array
    {
        $payload = [
            'uri' => $this->resolveWebhookUri(),
            'method' => 'POST',
            'enabled' => true,
            'pauseOnFail' => true,
        ];

        $email = trim((string) config('treeal_contas.webhook_error_email', ''));
        if ($email !== '') {
            $payload['email'] = $email;
        }

        $headerName = trim((string) config('treeal_contas.webhook_auth_header', ''));
        $headerValue = (string) config('treeal_contas.webhook_auth_value', '');
        if ($headerName !== '' && $headerValue !== '') {
            $payload['headers'] = [$headerName => $headerValue];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private static function messageFromBody(?array $body): string
    {
        if ($body === null) {
            return 'Erro ao registrar webhook na TREEAL Contas.';
        }

        $detail = isset($body['detail']) && is_string($body['detail']) ? trim($body['detail']) : '';
        if ($detail !== '') {
            return $detail;
        }

        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';
        if ($title !== '') {
            return $title;
        }

        return 'Erro ao registrar webhook na TREEAL Contas.';
    }
}
