<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Services\ClientBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint público (integração) para o cliente consultar o próprio saldo.
 *
 * Autenticação via token + secret (middleware check.token.secret), os mesmos
 * que o cliente já usa nas rotas de PIX. É somente leitura.
 */
class BalanceController extends Controller
{
    public function __construct(private readonly ClientBalanceService $balanceService)
    {
    }

    /**
     * @OA\Get(
     *     path="/api/wallet/balance",
     *     summary="Consultar saldo e movimentação do mês",
     *     description="Retorna o saldo disponível e a movimentação (entradas, saídas e fluxo líquido) do mês corrente do integrador autenticado. Somente leitura.",
     *     tags={"Wallet"},
     *     security={{"api_token":{}, "api_secret":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Saldo consultado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="moeda", type="string", example="BRL"),
     *                 @OA\Property(property="saldo_disponivel", type="number", format="float", example=299.47),
     *                 @OA\Property(property="entradas_mes", type="number", format="float", example=2.00),
     *                 @OA\Property(property="saidas_mes", type="number", format="float", example=1.00),
     *                 @OA\Property(property="fluxo_liquido_mes", type="number", format="float", example=1.00),
     *                 @OA\Property(
     *                     property="periodo",
     *                     type="object",
     *                     @OA\Property(property="inicio", type="string", format="date", example="2026-06-01"),
     *                     @OA\Property(property="fim", type="string", format="date", example="2026-06-30")
     *                 ),
     *                 @OA\Property(property="atualizado_em", type="string", format="date-time", example="2026-06-10T13:06:00-03:00")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Token ou Secret inválidos"),
     *     @OA\Response(response=403, description="Conta inativa ou bloqueada"),
     *     @OA\Response(response=429, description="Limite de requisições excedido"),
     *     @OA\Response(response=500, description="Erro interno do servidor")
     * )
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuário não autenticado',
            ], 401);
        }

        try {
            $data = $this->balanceService->getBalanceSummary($user);

            return response()->json([
                'status' => 'success',
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('Erro ao consultar saldo via API de integração', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Erro interno do servidor',
            ], 500);
        }
    }
}
