<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Simpay\SimpayPixAcquirerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ferramentas de diagnóstico contra a API Simpay (uso interno / suporte).
 */
class SimpayDebugController extends Controller
{
    /**
     * GET api/admin/simpay/receipt-transaction?id=30901108&uuid=E3398...&language=portuguese
     *
     * Pelo menos um de: id (transaction_id do cash out) ou uuid (EndToEnd).
     */
    public function receiptTransaction(Request $request, SimpayPixAcquirerService $simpay): JsonResponse
    {
        $idRaw = $request->query('id');
        $uuid = $request->query('uuid');
        $language = (string) $request->query('language', 'portuguese');

        $id = $idRaw !== null && $idRaw !== '' ? $idRaw : null;
        if ($uuid !== null && trim((string) $uuid) === '') {
            $uuid = null;
        }

        if ($id === null && $uuid === null) {
            return response()->json([
                'success' => false,
                'message' => 'Informe query id (transaction_id Simpay) ou uuid (EndToEnd / operationUuid).',
            ], 422);
        }

        if (! $simpay->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Integração Simpay não configurada ou inativa.',
            ], 503);
        }

        $result = $simpay->getReceiptTransaction($id, $uuid, $language);

        if (! ($result['success'] ?? false)) {
            $status = 502;
            $msg = strtolower((string) ($result['message'] ?? ''));
            if (str_contains($msg, 'not found') || str_contains($msg, 'não encontrad') || str_contains($msg, 'nao encontrad')) {
                $status = 404;
            }

            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Falha ao consultar Simpay.',
                'http_status' => $result['http_status'] ?? null,
                'simpay' => $result['raw'] ?? null,
            ], $status);
        }

        return response()->json([
            'success' => true,
            'simpay' => $result['data'] ?? [],
        ]);
    }
}
