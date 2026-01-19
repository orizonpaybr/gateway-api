<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NotificationPreferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class NotificationPreferenceController extends Controller
{
    private $preferenceService;

    public function __construct(NotificationPreferenceService $preferenceService)
    {
        $this->preferenceService = $preferenceService;
    }

    /**
     * Obter preferências de notificação do usuário
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPreferences(Request $request)
    {
        try {
            $user = $this->validateUser($request);
            if (!$user) {
                return $this->errorResponse('Usuário não autenticado', 401);
            }

            $preferences = $this->preferenceService->getUserPreferences($user->username);
            return $this->successResponse($preferences);

        } catch (\Exception $e) {
            Log::error('Erro ao obter preferências de notificação', [
                'error' => $e->getMessage()
            ]);
            return $this->errorResponse('Erro ao obter preferências', 500);
        }
    }

    /**
     * Atualizar preferências de notificação
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updatePreferences(Request $request)
    {
        try {
            $user = $this->validateUser($request);
            if (!$user) {
                return $this->errorResponse('Usuário não autenticado', 401);
            }

            $validator = Validator::make($request->all(), [
                'notify_transactions' => 'nullable|boolean',
                'notify_deposits' => 'nullable|boolean',
                'notify_withdrawals' => 'nullable|boolean',
                'notify_security' => 'nullable|boolean',
                'notify_system' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $data = $validator->validated();
            
            // Remover campos null
            $data = array_filter($data, fn($value) => !is_null($value));

            $preferences = $this->preferenceService->updatePreferences(
                $user->username,
                $data
            );

            return $this->successResponse($preferences, 'Preferências atualizadas com sucesso');

        } catch (\Exception $e) {
            Log::error('Erro ao atualizar preferências de notificação', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse('Erro ao atualizar preferências', 500);
        }
    }

    /**
     * Alternar uma preferência específica
     * 
     * @param Request $request
     * @param string $type
     * @return \Illuminate\Http\JsonResponse
     */
    public function togglePreference(Request $request, string $type)
    {
        try {
            $user = $this->validateUser($request);
            if (!$user) {
                return $this->errorResponse('Usuário não autenticado', 401);
            }

            $validTypes = [
                'notify_transactions',
                'notify_deposits',
                'notify_withdrawals',
                'notify_security',
                'notify_system'
            ];

            if (!in_array($type, $validTypes)) {
                return $this->errorResponse('Tipo de preferência inválido', 400);
            }

            $preferences = $this->preferenceService->getUserPreferences($user->username);
            $currentValue = $preferences[$type] ?? true;

            $updated = $this->preferenceService->updatePreferences(
                $user->username,
                [$type => !$currentValue]
            );

            return $this->successResponse($updated, 'Preferência atualizada com sucesso');

        } catch (\Exception $e) {
            Log::error('Erro ao alternar preferência', [
                'type' => $type,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Erro ao alternar preferência', 500);
        }
    }

    /**
     * Desabilitar todas as notificações
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function disableAll(Request $request)
    {
        try {
            $user = $this->validateUser($request);
            if (!$user) {
                return $this->errorResponse('Usuário não autenticado', 401);
            }

            $preferences = $this->preferenceService->disableAllNotifications($user->username);

            return $this->successResponse($preferences, 'Todas as notificações foram desabilitadas');

        } catch (\Exception $e) {
            Log::error('Erro ao desabilitar todas as notificações', [
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Erro ao desabilitar notificações', 500);
        }
    }

    /**
     * Habilitar todas as notificações
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function enableAll(Request $request)
    {
        try {
            $user = $this->validateUser($request);
            if (!$user) {
                return $this->errorResponse('Usuário não autenticado', 401);
            }

            $preferences = $this->preferenceService->enableAllNotifications($user->username);

            return $this->successResponse($preferences, 'Todas as notificações foram habilitadas');

        } catch (\Exception $e) {
            Log::error('Erro ao habilitar todas as notificações', [
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Erro ao habilitar notificações', 500);
        }
    }

    /**
     * Extrair usuário do request e validar autenticação
     * 
     * @param Request $request
     * @return \App\Models\User|null
     */
    private function getUserFromRequest(Request $request)
    {
        try {
            $token = $request->input('token');
            $secret = $request->input('secret');
            
            if (!$token || !$secret) {
                return null;
            }

            $userKeys = \App\Models\UsersKey::where('token', $token)
                ->where('secret', $secret)
                ->first();
            
            if (!$userKeys) {
                return null;
            }

            return \App\Models\User::where('username', $userKeys->user_id)->first();
            
        } catch (\Exception $e) {
            Log::error('Erro ao obter usuário do request', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Validar autenticação do usuário
     * Tenta JWT primeiro, depois token/secret no body (compatibilidade)
     * 
     * @param Request $request
     * @return \App\Models\User|null
     */
    private function validateUser(Request $request): ?\App\Models\User
    {
        // Tentar JWT primeiro (padrão para rotas protegidas)
        $user = $request->user() ?? $request->user_auth;
        
        // Fallback: token/secret no body (compatibilidade)
        if (!$user) {
            $user = $this->getUserFromRequest($request);
        }
        
        return $user;
    }
}

