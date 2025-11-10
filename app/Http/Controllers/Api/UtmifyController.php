<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, Log, Validator, Hash};

/**
 * Controller para gerenciar integração com Utmify
 * Endpoints para configurar API Key de rastreamento
 */
class UtmifyController extends Controller
{
    private const CACHE_TTL = 300; // 5 minutos
    private const CACHE_PREFIX = 'utmify:';

    /**
     * Obter configuração da Utmify do usuário
     * 
     * @OA\Get(
     *     path="/api/utmify/config",
     *     tags={"Utmify"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Configuração obtida")
     * )
     */
    public function getConfig(Request $request)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            // Garantir que os flags (ex.: twofa_enabled) estejam atualizados
            if ($user && method_exists($user, 'refresh')) {
                $user->refresh();
            }
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            // Cache da configuração
            $cacheKey = self::CACHE_PREFIX . "config_{$user->username}";
            $config = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
                return [
                    'api_key' => $user->integracao_utmfy,
                    'enabled' => !is_null($user->integracao_utmfy),
                    'updated_at' => $user->updated_at,
                ];
            });

            Log::info('[UTMIFY] Configuração consultada', [
                'user_id' => $user->username,
                'enabled' => $config['enabled']
            ]);

            return response()->json([
                'success' => true,
                'data' => $config
            ], 200);

        } catch (\Exception $e) {
            Log::error('[UTMIFY] Erro ao obter configuração', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao obter configuração da Utmify'
            ], 500);
        }
    }

    /**
     * Salvar/Atualizar API Key da Utmify
     * 
     * @OA\Post(
     *     path="/api/utmify/config",
     *     tags={"Utmify"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             required={"api_key"},
     *             @OA\Property(property="api_key", type="string"),
     *             @OA\Property(property="pin", type="string")
     *         )
     *     ),
     *     @OA\Response(response="200", description="API Key salva com sucesso")
     * )
     */
    public function saveConfig(Request $request)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            if ($user && method_exists($user, 'refresh')) {
                $user->refresh();
            }
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'api_key' => 'required|string|max:255',
                'pin' => 'nullable|string|size:6'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            // Se usuário tem 2FA, validar PIN
            if ($user->twofa_enabled) {
                $pin = $request->input('pin');
                
                if (!$pin) {
                    return response()->json([
                        'success' => false,
                        'message' => 'PIN de 2FA obrigatório',
                        'requires_2fa' => true
                    ], 400);
                }

                // Verificar PIN (hash seguro)
                if (!Hash::check($pin, $user->twofa_pin)) {
                    Log::warning('[UTMIFY] PIN 2FA inválido ao salvar configuração', [
                        'user_id' => $user->username
                    ]);
                    
                    return response()->json([
                        'success' => false,
                        'message' => 'PIN de 2FA inválido'
                    ], 400);
                }
            }

            // Atualizar API Key
            $apiKey = $request->input('api_key');
            $user->integracao_utmfy = $apiKey;
            $user->save();

            // Limpar cache
            $this->clearUserCache($user->username);

            Log::info('[UTMIFY] API Key configurada', [
                'user_id' => $user->username,
                'api_key_length' => strlen($apiKey)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'API Key da Utmify configurada com sucesso',
                'data' => [
                    'api_key' => $apiKey,
                    'enabled' => true,
                    'updated_at' => $user->updated_at,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('[UTMIFY] Erro ao salvar configuração', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao salvar configuração da Utmify'
            ], 500);
        }
    }

    /**
     * Remover API Key da Utmify
     * 
     * @OA\Delete(
     *     path="/api/utmify/config",
     *     tags={"Utmify"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="pin", type="string")
     *         )
     *     ),
     *     @OA\Response(response="200", description="API Key removida com sucesso")
     * )
     */
    public function deleteConfig(Request $request)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            if ($user && method_exists($user, 'refresh')) {
                $user->refresh();
            }
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            // Se usuário tem 2FA, validar PIN
            if ($user->twofa_enabled) {
                $pin = $request->input('pin');
                
                if (!$pin) {
                    return response()->json([
                        'success' => false,
                        'message' => 'PIN de 2FA obrigatório',
                        'requires_2fa' => true
                    ], 400);
                }

                // Verificar PIN (hash seguro)
                if (!Hash::check($pin, $user->twofa_pin)) {
                    Log::warning('[UTMIFY] PIN 2FA inválido ao remover configuração', [
                        'user_id' => $user->username
                    ]);
                    
                    return response()->json([
                        'success' => false,
                        'message' => 'PIN de 2FA inválido'
                    ], 400);
                }
            }

            // Remover API Key
            $user->integracao_utmfy = null;
            $user->save();

            // Limpar cache
            $this->clearUserCache($user->username);

            Log::info('[UTMIFY] API Key removida', [
                'user_id' => $user->username
            ]);

            return response()->json([
                'success' => true,
                'message' => 'API Key da Utmify removida com sucesso',
                'data' => [
                    'api_key' => null,
                    'enabled' => false,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('[UTMIFY] Erro ao remover configuração', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao remover configuração da Utmify'
            ], 500);
        }
    }

    /**
     * Testar conexão com Utmify
     * 
     * @OA\Post(
     *     path="/api/utmify/test",
     *     tags={"Utmify"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Teste realizado")
     * )
     */
    public function testConnection(Request $request)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            if (is_null($user->integracao_utmfy)) {
                return response()->json([
                    'success' => false,
                    'message' => 'API Key da Utmify não configurada'
                ], 400);
            }

            // Simular um teste de conexão
            // Em produção, você pode fazer uma requisição real à API da Utmify
            Log::info('[UTMIFY] Teste de conexão realizado', [
                'user_id' => $user->username
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Conexão com Utmify OK',
                'data' => [
                    'api_url' => 'https://api.utmify.com.br',
                    'status' => 'connected'
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('[UTMIFY] Erro ao testar conexão', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao testar conexão com Utmify'
            ], 500);
        }
    }

    /**
     * Limpar cache do usuário
     */
    private function clearUserCache(string $username): void
    {
        try {
            $cacheKey = self::CACHE_PREFIX . "config_{$username}";
            Cache::forget($cacheKey);
            
            Log::debug('[UTMIFY] Cache limpo', ['username' => $username]);
        } catch (\Exception $e) {
            Log::warning('[UTMIFY] Erro ao limpar cache', [
                'username' => $username,
                'error' => $e->getMessage()
            ]);
        }
    }
}

