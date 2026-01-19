<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\{FinancialTransactionsRequest, FinancialStatsRequest, UpdateDepositStatusRequest};
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
            // Validação e sanitização de entrada
            $filters = [
                'page' => max(1, (int) $request->get('page', 1)),
                'limit' => min(max(1, (int) $request->get('limit', 20)), 100),
                'busca' => $request->get('busca') ? trim($request->get('busca')) : null,
                'tipo_usuario' => $request->get('tipo_usuario'),
                'ordenar' => $this->validateSortOrder($request->get('ordenar', 'saldo_desc')),
            ];

            $data = $this->financialService->getWallets($filters);

            return $this->successResponse($data);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar carteiras', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'filters' => $request->all(),
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

    /**
     * Atualizar status de depósito
     * 
     * @param UpdateDepositStatusRequest $request
     * @param int $id ID do depósito
     * @return JsonResponse
     */
    public function updateDepositStatus(UpdateDepositStatusRequest $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validated();
            $newStatus = $validated['status'];

            // Obter usuário autenticado (middleware ensure.admin garante que existe)
            $user = $request->user() ?? $request->user_auth;
            $updatedBy = $user ? ($user->id ?? $user->user_id ?? 'system') : 'system';

            $deposit = $this->financialService->updateDepositStatus($id, $newStatus);

            Log::info('Status de depósito atualizado', [
                'deposit_id' => $id,
                'new_status' => $newStatus,
                'updated_by' => $updatedBy,
            ]);

            return $this->successResponse([
                'deposit' => $deposit,
                'message' => 'Status atualizado com sucesso',
            ]);
        } catch (\Exception $e) {
            $statusCode = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;

            Log::error('Erro ao atualizar status de depósito', [
                'error' => $e->getMessage(),
                'deposit_id' => $id,
                'new_status' => $request->input('status'),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse($e->getMessage(), $statusCode);
        }
    }

    // ========== Métodos Helper ==========

    /**
     * Validar ordem de ordenação
     */
    private function validateSortOrder(?string $order): string
    {
        $allowedOrders = ['saldo_desc', 'saldo_asc', 'nome_asc'];
        return in_array($order, $allowedOrders) ? $order : 'saldo_desc';
    }
}
