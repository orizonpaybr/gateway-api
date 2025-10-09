<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\PushToken;
use App\Services\PushNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    private $pushService;

    public function __construct(PushNotificationService $pushService)
    {
        $this->pushService = $pushService;
    }

    /**
     * Registrar token de push do dispositivo
     */
    public function registerToken(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'token' => 'required|string',
                'platform' => 'nullable|string|in:expo,ios,android',
                'device_id' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400)->header('Access-Control-Allow-Origin', '*');
            }

            // Obter usuário do request (usando middleware check.token.secret)
            $user = $this->getUserFromRequest($request);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $token = $request->input('token');
            $platform = $request->input('platform', 'expo');
            $deviceId = $request->input('device_id');

            $result = $this->pushService->registerToken($user->username, $token, $platform, $deviceId);

            if ($result) {
                return response()->json([
                    'success' => true,
                    'message' => 'Token registrado com sucesso'
                ])->header('Access-Control-Allow-Origin', '*');
            }

            return response()->json([
                'success' => false,
                'message' => 'Erro ao registrar token'
            ], 500)->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao registrar token de push', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Obter notificações do usuário
     */
    public function getNotifications(Request $request)
    {
        try {
            $user = $this->getUserFromRequest($request);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $page = $request->get('page', 1);
            $limit = min($request->get('limit', 20), 100);
            $unreadOnly = $request->get('unread_only', false);

            $query = Notification::where('user_id', $user->username);

            if ($unreadOnly) {
                $query->whereNull('read_at');
            }

            $notifications = $query->orderBy('created_at', 'desc')
                ->paginate($limit, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'data' => [
                    'notifications' => $notifications->items(),
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                    'unread_count' => Notification::where('user_id', $user->username)
                        ->whereNull('read_at')
                        ->count()
                ]
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao obter notificações', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Marcar notificação como lida
     */
    public function markAsRead(Request $request, $id)
    {
        try {
            $user = $this->getUserFromRequest($request);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $notification = Notification::where('id', $id)
                ->where('user_id', $user->username)
                ->first();

            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notificação não encontrada'
                ], 404)->header('Access-Control-Allow-Origin', '*');
            }

            $notification->markAsRead();

            return response()->json([
                'success' => true,
                'message' => 'Notificação marcada como lida'
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao marcar notificação como lida', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Marcar todas as notificações como lidas
     */
    public function markAllAsRead(Request $request)
    {
        try {
            $user = $this->getUserFromRequest($request);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $updated = Notification::where('user_id', $user->username)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => "{$updated} notificações marcadas como lidas"
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao marcar todas as notificações como lidas', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Obter estatísticas de notificações
     */
    public function getStats(Request $request)
    {
        try {
            $user = $this->getUserFromRequest($request);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $stats = $this->pushService->getNotificationStats($user->username);

            return response()->json([
                'success' => true,
                'data' => $stats
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao obter estatísticas de notificações', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Desativar token de push
     */
    public function deactivateToken(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'token' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400)->header('Access-Control-Allow-Origin', '*');
            }

            $token = $request->input('token');
            $result = $this->pushService->deactivateToken($token);

            if ($result) {
                return response()->json([
                    'success' => true,
                    'message' => 'Token desativado com sucesso'
                ])->header('Access-Control-Allow-Origin', '*');
            }

            return response()->json([
                'success' => false,
                'message' => 'Token não encontrado'
            ], 404)->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao desativar token', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Extrair usuário do request (usando middleware check.token.secret)
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
}
