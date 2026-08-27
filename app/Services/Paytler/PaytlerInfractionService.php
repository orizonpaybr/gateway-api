<?php

namespace App\Services\Paytler;

use App\Helpers\SecureHttp;
use Illuminate\Support\Facades\Log;

/**
 * MED (infrações) da Paytler — Bearer, sob /v1/customers/med.
 *   GET  /med/list-med       (dateFrom, dateTo, status, limitPerPage, page)
 *   POST /med/defend-infraction { infractionId, message }
 *
 * Contrato espelha {@see \App\Services\Simpay\SimpayInfractionService} para plugar
 * no mesmo fluxo do PixInfracoesController. Paytler não expõe upload de evidência,
 * então a defesa é só texto.
 */
class PaytlerInfractionService
{
    private string $baseUrl;
    private int $timeout;

    public function __construct(private readonly PaytlerAuthService $auth)
    {
        $this->baseUrl = rtrim((string) config('paytler.base_url'), '/');
        $this->timeout = (int) config('paytler.timeout', 30);
    }

    public function isConfigured(): bool
    {
        return filled(config('paytler.client_id')) && filled(config('paytler.client_secret'));
    }

    /**
     * GET /med/list-med — lista infrações. Todos os params são obrigatórios na Paytler.
     *
     * @param  array<string, string|int|null>  $params
     * @return array{success:bool,data?:array,message?:string}
     */
    public function listInfractions(array $params = []): array
    {
        $query = array_merge([
            'dateFrom' => now()->subDays(30)->toDateString(),
            'dateTo' => now()->toDateString(),
            'status' => 'WAITING',
            'limitPerPage' => 50,
            'page' => 1,
        ], array_filter($params, static fn ($v) => $v !== null && $v !== ''));

        return $this->getJson('/med/list-med', $query);
    }

    /**
     * Defesa da MED — POST /med/defend-infraction { infractionId, message }.
     * $files é ignorado (Paytler não tem upload de evidência para MED).
     *
     * @param  array<int, mixed>  $files
     * @return array{success:bool,raw?:array,message?:string}
     */
    public function submitDefense(string $infractionId, string $defense, array $files = []): array
    {
        $id = trim($infractionId);
        if ($id === '') {
            return ['success' => false, 'message' => 'infractionId obrigatório.'];
        }
        if (trim($defense) === '') {
            return ['success' => false, 'message' => 'Texto da defesa é obrigatório.'];
        }
        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => 'PAYTLER não configurada.'];
        }

        $payload = [
            'infractionId' => $id,
            'message' => mb_substr(trim($defense), 0, 1000),
        ];

        $url = $this->baseUrl.'/med/defend-infraction';

        try {
            $response = SecureHttp::post($url, $payload, $this->auth->authHeaders(), $this->timeout);
            $body = $response->json();

            if ($response->status() === 401) {
                $this->auth->invalidateToken();
                $response = SecureHttp::post($url, $payload, $this->auth->authHeaders(), $this->timeout);
                $body = $response->json();
            }

            if (! $response->successful() || ! is_array($body)) {
                return ['success' => false, 'message' => self::msg(is_array($body) ? $body : null, 'Erro ao enviar defesa MED na PAYTLER.')];
            }

            return ['success' => true, 'raw' => $body];
        } catch (\Throwable $e) {
            Log::error('[PAYTLER][MED] Falha ao enviar defesa', ['infraction_id' => $id, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Erro ao conectar com PAYTLER (MED): '.$e->getMessage()];
        }
    }

    /**
     * @param  array<string, string|int|null>  $query
     * @return array{success:bool,data?:array,message?:string}
     */
    private function getJson(string $path, array $query = []): array
    {
        $url = $this->baseUrl.$path;

        try {
            $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout, $query);

            if ($response->status() === 401) {
                $this->auth->invalidateToken();
                $response = SecureHttp::get($url, $this->auth->authHeaders(), $this->timeout, $query);
            }

            $body = $response->json();
            if ($response->successful() && is_array($body)) {
                return ['success' => true, 'data' => $body];
            }

            return ['success' => false, 'message' => self::msg(is_array($body) ? $body : null, 'Erro na API MED PAYTLER.')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Erro ao conectar com PAYTLER (MED): '.$e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private static function msg(?array $body, string $default): string
    {
        if (! is_array($body)) {
            return $default;
        }
        $msg = $body['message'] ?? $body['error'] ?? $default;
        if (is_array($msg)) {
            $msg = implode('; ', array_map('strval', $msg));
        }

        return (string) $msg;
    }
}
