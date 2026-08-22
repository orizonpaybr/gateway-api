<?php

namespace App\Services\Simpay;

use App\Helpers\SecureHttp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MED (Mecanismo Especial de Devolução) da SIMPAY — endpoints v3 (/v3/meds/...),
 * apenas Bearer (não é cash-in/out, não exige HMAC). Contrato espelha
 * {@see \App\Services\TreealContas\TreealContasInfractionService} para plugar no
 * mesmo fluxo do PixInfracoesController.
 *
 * @see https://simpay-prod.readme.io/reference/med-api
 */
class SimpayInfractionService
{
    private string $rootUrl;

    private int $timeout;

    public function __construct(private readonly SimpayAuthService $auth)
    {
        $this->rootUrl = rtrim((string) config('simpay.base_url_root', 'https://api.somossimpay.com.br'), '/');
        $this->timeout = (int) config('simpay.timeout', 30);
    }

    public function isConfigured(): bool
    {
        return filled(config('simpay.client_id')) && filled(config('simpay.client_secret'));
    }

    /**
     * GET /v3/meds/ — lista MEDs (paginação/filtros por query).
     *
     * @param  array<string, string|int|float|null>  $params
     * @return array{success:bool,data?:array,message?:string}
     */
    public function listInfractions(array $params = []): array
    {
        return $this->getJson('/v3/meds/', $params);
    }

    /**
     * GET /v3/meds/{med_id} — detalhes de uma MED.
     *
     * @return array{success:bool,data?:array,message?:string}
     */
    public function getInfraction(string $medId): array
    {
        $id = trim($medId);
        if ($id === '') {
            return ['success' => false, 'message' => 'med_id obrigatório.'];
        }

        return $this->getJson('/v3/meds/'.rawurlencode($id));
    }

    /**
     * Defesa da MED = contesta a fraude (analysis_result = REJECTED) com justificativa
     * + evidências (upload prévio). Mantém a assinatura do contrato de infração.
     *
     * @param  array<int, UploadedFile|array{path:string,name?:string}>  $files
     * @return array{success:bool,raw?:array,message?:string}
     */
    public function submitDefense(string $medId, string $defense, array $files = []): array
    {
        $id = trim($medId);
        if ($id === '') {
            return ['success' => false, 'message' => 'med_id obrigatório.'];
        }
        if (trim($defense) === '') {
            return ['success' => false, 'message' => 'Texto da defesa é obrigatório.'];
        }
        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => 'SIMPAY não configurada.'];
        }

        // 1) Sobe evidências (se houver) — precisa acontecer com a MED ainda em WAITING.
        foreach ($files as $file) {
            $up = $this->uploadFile($id, $file);
            if (! ($up['success'] ?? false)) {
                return ['success' => false, 'message' => $up['message'] ?? 'Falha ao subir evidência da defesa.'];
            }
        }

        // 2) Submete a análise contestando a fraude.
        return $this->submitAnalysis($id, 'REJECTED', $defense);
    }

    /**
     * POST /v3/meds/{med_id}/analysis — responde a análise.
     * analysis_result: REJECTED (contesta) ou ACCEPTED (concorda → estorno).
     * fraud_type é obrigatório quando ACCEPTED.
     *
     * @return array{success:bool,raw?:array,message?:string}
     */
    public function submitAnalysis(string $medId, string $analysisResult, ?string $details = null, ?string $fraudType = null): array
    {
        $id = trim($medId);
        if ($id === '') {
            return ['success' => false, 'message' => 'med_id obrigatório.'];
        }

        $result = strtoupper(trim($analysisResult));
        $payload = ['analysis_result' => $result];
        if ($details !== null && trim($details) !== '') {
            $payload['analysis_details_user'] = mb_substr(trim($details), 0, 1000);
        }
        if ($result === 'ACCEPTED') {
            $payload['fraud_type'] = $fraudType !== null && trim($fraudType) !== '' ? strtoupper(trim($fraudType)) : 'UNKNOWN';
        }

        $url = $this->rootUrl.'/v3/meds/'.rawurlencode($id).'/analysis';

        try {
            $response = SecureHttp::post($url, $payload, $this->auth->authHeaders(), $this->timeout);
            $body = $response->json();

            if ($response->status() === 401) {
                $this->auth->invalidateToken();
                $response = SecureHttp::post($url, $payload, $this->auth->authHeaders(), $this->timeout);
                $body = $response->json();
            }

            if (! $response->successful() || ! is_array($body)) {
                return ['success' => false, 'message' => self::msg(is_array($body) ? $body : null, 'Erro ao enviar análise MED na SIMPAY.')];
            }

            return ['success' => true, 'raw' => $body];
        } catch (\Throwable $e) {
            Log::error('[SIMPAY][MED] Falha ao enviar análise', ['med_id' => $id, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Erro ao conectar com SIMPAY (MED): '.$e->getMessage()];
        }
    }

    /**
     * POST /v3/meds/{med_id}/file/upload (multipart, campo `file`).
     *
     * @param  UploadedFile|array{path:string,name?:string}  $file
     * @return array{success:bool,raw?:array,message?:string}
     */
    public function uploadFile(string $medId, UploadedFile|array $file): array
    {
        $id = trim($medId);
        if ($id === '') {
            return ['success' => false, 'message' => 'med_id obrigatório.'];
        }

        [$path, $name] = $this->resolveFile($file);
        if ($path === null) {
            return ['success' => false, 'message' => 'Arquivo de evidência inválido ou ilegível.'];
        }

        $url = $this->rootUrl.'/v3/meds/'.rawurlencode($id).'/file/upload';
        $bearer = (string) ($this->auth->authHeaders()['Authorization'] ?? '');

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders(['Authorization' => $bearer, 'Accept' => 'application/json'])
                ->withOptions(['curl' => [\CURLOPT_IPRESOLVE => \CURL_IPRESOLVE_V4]])
                ->attach('file', (string) file_get_contents($path), (string) $name)
                ->post($url);

            $body = $response->json();
            if (! $response->successful() || ! is_array($body)) {
                return ['success' => false, 'message' => self::msg(is_array($body) ? $body : null, 'Falha ao subir evidência MED.')];
            }

            return ['success' => true, 'raw' => $body];
        } catch (\Throwable $e) {
            Log::error('[SIMPAY][MED] Falha no upload de evidência', ['med_id' => $id, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Erro ao subir evidência MED: '.$e->getMessage()];
        }
    }

    /**
     * @param  array<string, string|int|float|null>  $query
     * @return array{success:bool,data?:array,message?:string}
     */
    private function getJson(string $path, array $query = []): array
    {
        $url = $this->rootUrl.$path;

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

            return ['success' => false, 'message' => self::msg(is_array($body) ? $body : null, 'Erro na API MED SIMPAY.'), 'data' => is_array($body) ? $body : null];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Erro ao conectar com SIMPAY (MED): '.$e->getMessage()];
        }
    }

    /**
     * @param  UploadedFile|array{path:string,name?:string}  $file
     * @return array{0:?string,1:?string}
     */
    private function resolveFile(UploadedFile|array $file): array
    {
        if ($file instanceof UploadedFile) {
            $p = $file->getRealPath();
            if ($p !== false && is_readable($p)) {
                return [$p, $file->getClientOriginalName() ?: 'evidencia'];
            }

            return [null, null];
        }

        $p = $file['path'] ?? null;
        if (is_string($p) && is_readable($p)) {
            return [$p, (string) ($file['name'] ?? basename($p))];
        }

        return [null, null];
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private static function msg(?array $body, string $default): string
    {
        if (! is_array($body)) {
            return $default;
        }

        return (string) ($body['message'] ?? $body['detail'] ?? $body['erro_descriptor'] ?? $default);
    }
}
