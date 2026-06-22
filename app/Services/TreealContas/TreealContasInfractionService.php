<?php

namespace App\Services\TreealContas;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Infrações Pix (MED — Mecanismo Especial de Devolução) na API Contas Treeal / ONZ.
 *
 * Endpoints:
 *  - GET  /infractions                       (escopo infractions.read)
 *  - GET  /infractions/{infractionId}        (escopo infractions.read)
 *  - POST /infractions/{infractionId}/defense (escopo infractions.write, multipart)
 *
 * Uma infração é a forma de contestar uma operação Pix por fraude/falha operacional.
 * Quando aberta contra a conta recebedora (Coratri/Treeal), permite bloqueio cautelar,
 * defesa e, se confirmada, a devolução via MED.
 */
class TreealContasInfractionService
{
    public function __construct(
        private readonly TreealContasApiClient $client,
        private readonly TreealContasAuthService $auth,
    ) {}

    public function isConfigured(): bool
    {
        return $this->auth->isConfigured();
    }

    /**
     * Lista infrações abertas contra a conta. A API exige a janela last_change_start/end.
     *
     * @param  array{last_change_start?: string, last_change_end?: string, page_offset?: int, page_limit?: int, sort_by?: string, status?: string}  $params
     * @return array{success: bool, message?: string, raw?: array<string, mixed>}
     */
    public function listInfractions(array $params = []): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => 'TREEAL Contas não configurada.'];
        }

        $query = $params;
        if (empty($query['last_change_start'])) {
            $query['last_change_start'] = Carbon::now()->subDays(90)->toIso8601String();
        }
        if (empty($query['last_change_end'])) {
            $query['last_change_end'] = Carbon::now()->toIso8601String();
        }

        try {
            $response = $this->client->get('/infractions', $query);
            $body = $response->json();

            if (! $response->successful() || ! is_array($body)) {
                return [
                    'success' => false,
                    'message' => self::messageFromBody(is_array($body) ? $body : null, 'Erro ao listar infrações na TREEAL.'),
                ];
            }

            return ['success' => true, 'raw' => $body];
        } catch (\Throwable $e) {
            Log::error('[TREEAL_CONTAS][INFRACTION] Falha ao listar infrações', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Erro ao conectar com TREEAL Contas: '.$e->getMessage()];
        }
    }

    /**
     * Detalhe de uma infração (inclui endToEndId e histórico de defesas).
     *
     * @return array{success: bool, message?: string, raw?: array<string, mixed>}
     */
    public function getInfraction(string $infractionId): array
    {
        $id = trim($infractionId);
        if ($id === '') {
            return ['success' => false, 'message' => 'infractionId obrigatório.'];
        }
        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => 'TREEAL Contas não configurada.'];
        }

        try {
            $response = $this->client->get('/infractions/'.rawurlencode($id));
            $body = $response->json();

            if (! $response->successful() || ! is_array($body)) {
                return [
                    'success' => false,
                    'message' => self::messageFromBody(is_array($body) ? $body : null, 'Erro ao consultar infração na TREEAL.'),
                ];
            }

            return ['success' => true, 'raw' => $body];
        } catch (\Throwable $e) {
            Log::error('[TREEAL_CONTAS][INFRACTION] Falha ao consultar infração', [
                'infraction_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Erro ao conectar com TREEAL Contas: '.$e->getMessage()];
        }
    }

    /**
     * Submete defesa contra uma infração (multipart: campo "defense" + arquivos opcionais).
     *
     * @param  array<int, UploadedFile|array{path: string, name?: string}>  $files
     * @return array{success: bool, message?: string, raw?: array<string, mixed>}
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
            return ['success' => false, 'message' => 'TREEAL Contas não configurada.'];
        }

        $attachments = [];
        foreach ($files as $file) {
            $resolved = $this->resolveFile($file);
            if ($resolved !== null) {
                $attachments[] = $resolved;
            }
        }

        try {
            $response = $this->client->postMultipart(
                '/infractions/'.rawurlencode($id).'/defense',
                ['defense' => $defense],
                $attachments,
            );
            $body = $response->json();

            if (! $response->successful() || ! is_array($body)) {
                return [
                    'success' => false,
                    'message' => self::messageFromBody(is_array($body) ? $body : null, 'Erro ao enviar defesa na TREEAL.'),
                ];
            }

            return ['success' => true, 'raw' => $body];
        } catch (\Throwable $e) {
            Log::error('[TREEAL_CONTAS][INFRACTION] Falha ao enviar defesa', [
                'infraction_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Erro ao conectar com TREEAL Contas: '.$e->getMessage()];
        }
    }

    /**
     * @param  UploadedFile|array{path: string, name?: string}  $file
     * @return array{name: string, contents: string, filename: string}|null
     */
    private function resolveFile(UploadedFile|array $file): ?array
    {
        if ($file instanceof UploadedFile) {
            $path = $file->getRealPath();
            if ($path === false || ! is_readable($path)) {
                return null;
            }

            return [
                'name' => 'files',
                'contents' => (string) file_get_contents($path),
                'filename' => $file->getClientOriginalName() ?: 'anexo',
            ];
        }

        $path = trim((string) ($file['path'] ?? ''));
        if ($path === '' || ! is_readable($path)) {
            return null;
        }

        return [
            'name' => 'files',
            'contents' => (string) file_get_contents($path),
            'filename' => (string) ($file['name'] ?? basename($path)),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private static function messageFromBody(?array $body, string $default): string
    {
        if ($body === null) {
            return $default;
        }

        $detail = isset($body['detail']) && is_string($body['detail']) ? trim($body['detail']) : '';
        if ($detail !== '') {
            return $detail;
        }

        $title = isset($body['title']) && is_string($body['title']) ? trim($body['title']) : '';

        return $title !== '' ? $title : $default;
    }
}
