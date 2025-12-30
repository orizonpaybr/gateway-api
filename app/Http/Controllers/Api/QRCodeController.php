<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QRCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class QRCodeController extends Controller
{
    public function __construct(
        private QRCodeService $qrCodeService
    ) {}

    /**
     * Lista de QR Codes dinâmicos da tabela solicitacoes com cache Redis
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user() ?? ($request->user_auth ?? null);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $filters = [
                'page' => $request->get('page', 1),
                'limit' => $request->get('limit', 20),
                'busca' => $request->get('busca', ''),
                'data_inicio' => $request->get('data_inicio'),
                'data_fim' => $request->get('data_fim'),
                'status' => $request->get('status'),
            ];

            $payload = $this->qrCodeService->getQRCodes($user->username, $filters);

            return response()->json([
                'success' => true,
                'data' => $payload
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao listar QR Codes dinâmicos', [
                'error' => $e->getMessage(),
                'user_id' => $user->username ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Limpa o cache de QR codes de um usuário específico
     */
    public static function clearUserCache(string $userId): void
    {
        try {
            \Illuminate\Support\Facades\Cache::forget("qrcodes_user_{$userId}");
        } catch (\Exception $e) {
            Log::error('Erro ao limpar cache de QR codes', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
}

