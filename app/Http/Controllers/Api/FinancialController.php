<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\{FinancialTransactionsRequest, FinancialStatsRequest};
use App\Services\FinancialService;
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Facades\Log;

/**
 * Controller para endpoints financeiros do admin
 * 
 * Implementa boas práticas:
 * - Service Layer Pattern (FinancialService)
 * - Form Requests para validação
 * - Cache Redis para performance
 * - Queries otimizadas
 * - DRY (Don't Repeat Yourself)
 * - Clean Code
 * - Escalabilidade e Manutenibilidade
 */
class FinancialController extends Controller
{
    /**
     * Service para lógica de negócio financeira
     */
    protected FinancialService $financialService;

    /**
     * Constructor
     */
    public function __construct(FinancialService $financialService)
    {
        $this->financialService = $financialService;
    }

    /**
     * Listar todas as transações (depósitos + saques)
     * 
     * @param FinancialTransactionsRequest $request
     * @return JsonResponse
     */
    public function getAllTransactions(FinancialTransactionsRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $data = $this->financialService->getAllTransactions($filters);

            return $this->successResponse($data);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar transações financeiras', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'filters' => $request->all(),
            ]);

            return $this->errorResponse('Erro ao buscar transações', 500);
        }
    }

    /**
     * Estatísticas das transações
     * 
     * @param FinancialStatsRequest $request
     * @return JsonResponse
     */
    public function getTransactionsStats(FinancialStatsRequest $request): JsonResponse
    {
        try {
            $periodo = $request->validated()['periodo'] ?? 'hoje';
            $data = $this->financialService->getTransactionsStats($periodo);

            return $this->successResponse($data);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar estatísticas de transações', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Erro ao buscar estatísticas', 500);
        }
    }

    /**
     * Listar carteiras (usuários com saldo)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getWallets(Request $request): JsonResponse
    {
        try {
            $filters = [
                'page' => $request->get('page', 1),
                'limit' => min($request->get('limit', 20), 100),
                'busca' => $request->get('busca'),
                'tipo_usuario' => $request->get('tipo_usuario'),
                'ordenar' => $request->get('ordenar', 'saldo_desc'),
            ];

            $data = $this->financialService->getWallets($filters);

            return $this->successResponse($data);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar carteiras', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Erro ao buscar carteiras', 500);
        }
    }

    /**
     * Estatísticas das carteiras
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getWalletsStats(Request $request): JsonResponse
    {
        try {
            $data = $this->financialService->getWalletsStats();

            return $this->successResponse($data);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar estatísticas de carteiras', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Erro ao buscar estatísticas', 500);
        }
    }

    /**
     * Listar apenas depósitos (entradas)
     * 
     * @param FinancialTransactionsRequest $request
     * @return JsonResponse
     */
    public function getDeposits(FinancialTransactionsRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $data = $this->financialService->getDeposits($filters);

            return $this->successResponse($data);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar depósitos', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'filters' => $request->all(),
            ]);

            return $this->errorResponse('Erro ao buscar depósitos', 500);
        }
    }

    /**
     * Estatísticas dos depósitos
     * 
     * @param FinancialStatsRequest $request
     * @return JsonResponse
     */
    public function getDepositsStats(FinancialStatsRequest $request): JsonResponse
    {
        try {
            $periodo = $request->validated()['periodo'] ?? 'hoje';
            $data = $this->financialService->getDepositsStats($periodo);

            return $this->successResponse($data);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar estatísticas de depósitos', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Erro ao buscar estatísticas', 500);
        }
    }

    /**
     * Listar apenas saques (saídas)
     * 
     * @param FinancialTransactionsRequest $request
     * @return JsonResponse
     */
    public function getWithdrawals(FinancialTransactionsRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $data = $this->financialService->getWithdrawals($filters);

            return $this->successResponse($data);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar saques', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'filters' => $request->all(),
            ]);

            return $this->errorResponse('Erro ao buscar saques', 500);
        }
    }

    /**
     * Estatísticas dos saques
     * 
     * @param FinancialStatsRequest $request
     * @return JsonResponse
     */
    public function getWithdrawalsStats(FinancialStatsRequest $request): JsonResponse
    {
        try {
            $periodo = $request->validated()['periodo'] ?? 'hoje';
            $data = $this->financialService->getWithdrawalsStats($periodo);

            return $this->successResponse($data);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar estatísticas de saques', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Erro ao buscar estatísticas', 500);
        }
    }

    // ========== Métodos Helper ==========

    /**
     * Resposta de sucesso padronizada
     */
    private function successResponse($data): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Resposta de erro padronizada
     */
    private function errorResponse(string $message, int $statusCode = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $statusCode);
    }
}
