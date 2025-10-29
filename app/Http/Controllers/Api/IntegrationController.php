<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UsersKey;
use App\Traits\IPManagementTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

/**
 * Controller para gerenciar integrações de API
 * Endpoints para obter/regenerar credenciais e gerenciar IPs autorizados
 */
class IntegrationController extends Controller
{
    use IPManagementTrait;

    /**
     * Obter credenciais de API do usuário
     * 
     * @OA\Get(
     *     path="/api/integration/credentials",
     *     tags={"Integration"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Credenciais obtidas")
     * )
     */
    public function getCredentials(Request $request)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            // Cache das credenciais (5 minutos)
            $cacheKey = "api_credentials_{$user->username}";
            $credentials = Cache::remember($cacheKey, 300, function () use ($user) {
                $userKeys = UsersKey::where('user_id', $user->username)->first();
                
                // Se não existir, criar automaticamente
                if (!$userKeys) {
                    $userKeys = $this->createCredentials($user);
                }
                
                return $userKeys;
            });

            Log::info('[INTEGRATION] Credenciais consultadas', [
                'user_id' => $user->username,
                'has_token' => !empty($credentials->token),
                'has_secret' => !empty($credentials->secret)
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'client_key' => $credentials->token,
                    'client_secret' => $credentials->secret,
                    'status' => $credentials->status == 1 ? 'active' : 'inactive',
                    'created_at' => $credentials->created_at,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('[INTEGRATION] Erro ao obter credenciais', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao obter credenciais'
            ], 500);
        }
    }

    /**
     * Regenerar Client Secret
     * ATENÇÃO: Invalida todas as integrações existentes
     * 
     * @OA\Post(
     *     path="/api/integration/regenerate-secret",
     *     tags={"Integration"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Secret regenerado")
     * )
     */
    public function regenerateSecret(Request $request)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            // Se 2FA estiver ativo para o usuário, exigir PIN válido
            if (!empty($user->twofa_enabled) && !empty($user->twofa_pin)) {
                $request->validate([
                    'pin' => ['required','string','size:6','regex:/^\d+$/'],
                ], [
                    'pin.required' => 'PIN de 2FA é obrigatório para regenerar o secret.',
                    'pin.size' => 'PIN deve ter exatamente 6 dígitos.',
                    'pin.regex' => 'PIN deve conter apenas dígitos.',
                ]);

                if (!Hash::check($request->input('pin'), $user->twofa_pin)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'PIN de 2FA inválido',
                    ], 401);
                }
            }

            $userKeys = UsersKey::where('user_id', $user->username)->first();
            
            if (!$userKeys) {
                $userKeys = $this->createCredentials($user);
            }

            // Gerar novo secret
            $newSecret = Str::uuid()->toString();
            $userKeys->secret = $newSecret;
            $userKeys->save();

            // Limpar cache
            Cache::forget("api_credentials_{$user->username}");

            Log::warning('[INTEGRATION] Client Secret regenerado', [
                'user_id' => $user->username,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Client Secret regenerado com sucesso. Atualize suas integrações!',
                'data' => [
                    'client_key' => $userKeys->token,
                    'client_secret' => $newSecret,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('[INTEGRATION] Erro ao regenerar secret', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao regenerar secret'
            ], 500);
        }
    }

    /**
     * Obter IPs autorizados
     * 
     * @OA\Get(
     *     path="/api/integration/allowed-ips",
     *     tags={"Integration"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="IPs autorizados")
     * )
     */
    public function getAllowedIPs(Request $request)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            // Cache dos IPs (2 minutos)
            $cacheKey = "allowed_ips_{$user->username}";
            $ips = Cache::remember($cacheKey, 120, function () use ($user) {
                $userRefreshed = User::where('username', $user->username)->first();
                if (!$userRefreshed) {
                    return [];
                }
                return \App\Traits\IPManagementTrait::getAllowedIPs($userRefreshed);
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'ips' => $ips,
                    'count' => count($ips)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('[INTEGRATION] Erro ao obter IPs', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao obter IPs autorizados'
            ], 500);
        }
    }

    /**
     * Adicionar IP autorizado
     * 
     * @OA\Post(
     *     path="/api/integration/allowed-ips",
     *     tags={"Integration"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             required={"ip"},
     *             @OA\Property(property="ip", type="string", example="192.168.1.1")
     *         )
     *     ),
     *     @OA\Response(response="200", description="IP adicionado")
     * )
     */
    public function addAllowedIP(Request $request)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            // Construir regras de validação de forma condicional
            $rules = [
                'ip' => 'required|ip'
            ];
            $messages = [
                'ip.required' => 'O IP é obrigatório',
                'ip.ip' => 'Formato de IP inválido'
            ];

            // Se 2FA estiver ativo, exigir PIN
            if (!empty($user->twofa_enabled) && !empty($user->twofa_pin)) {
                $rules['pin'] = 'required|string|size:6|regex:/^\d+$/';
                $messages['pin.required'] = 'PIN de 2FA é obrigatório para adicionar IP.';
                $messages['pin.size'] = 'PIN deve ter exatamente 6 dígitos.';
                $messages['pin.regex'] = 'PIN deve conter apenas dígitos.';
            }

            $validator = Validator::make($request->all(), $rules, $messages);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            // Verificar PIN se 2FA estiver ativo
            if (!empty($user->twofa_enabled) && !empty($user->twofa_pin)) {
                if (!Hash::check($request->input('pin'), $user->twofa_pin)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'PIN de 2FA inválido',
                    ], 401);
                }
            }

            $ip = $request->input('ip');
            
            // Buscar usuário atualizado
            $userRefreshed = User::where('username', $user->username)->first();
            
            if (!$userRefreshed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado'
                ], 404);
            }
            
            $result = \App\Traits\IPManagementTrait::addAllowedIP($userRefreshed, $ip);
            
            if (!$result) {
                return response()->json([
                    'success' => false,
                    'message' => 'IP já está autorizado'
                ], 400);
            }

            // Limpar cache
            Cache::forget("allowed_ips_{$user->username}");

            Log::info('[INTEGRATION] IP adicionado', [
                'user_id' => $user->username,
                'ip_added' => $ip,
                'by_ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'IP autorizado com sucesso',
                'data' => [
                    'ips' => \App\Traits\IPManagementTrait::getAllowedIPs($userRefreshed)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('[INTEGRATION] Erro ao adicionar IP', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao adicionar IP'
            ], 500);
        }
    }

    /**
     * Remover IP autorizado
     * 
     * @OA\Delete(
     *     path="/api/integration/allowed-ips/{ip}",
     *     tags={"Integration"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="ip",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(response="200", description="IP removido")
     * )
     */
    public function removeAllowedIP(Request $request, $ip)
    {
        try {
            $user = $request->user() ?? $request->user_auth;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401);
            }

            // Validar formato do IP
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Formato de IP inválido'
                ], 400);
            }

            // Se 2FA estiver ativo, exigir PIN
            // Para DELETE, o body pode vir como JSON na requisição
            if (!empty($user->twofa_enabled) && !empty($user->twofa_pin)) {
                // Ler body do request (DELETE pode ter body)
                $requestBody = json_decode($request->getContent(), true);
                $pin = $requestBody['pin'] ?? $request->input('pin');
                
                if (empty($pin)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'PIN de 2FA é obrigatório para remover IP.',
                    ], 400);
                }

                if (strlen($pin) !== 6 || !preg_match('/^\d+$/', $pin)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'PIN deve ter exatamente 6 dígitos.',
                    ], 400);
                }

                if (!Hash::check($pin, $user->twofa_pin)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'PIN de 2FA inválido',
                    ], 401);
                }
            }

            // Buscar usuário atualizado
            $userRefreshed = User::where('username', $user->username)->first();
            
            if (!$userRefreshed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado'
                ], 404);
            }
            
            $result = \App\Traits\IPManagementTrait::removeAllowedIP($userRefreshed, $ip);
            
            if (!$result) {
                return response()->json([
                    'success' => false,
                    'message' => 'IP não encontrado'
                ], 404);
            }

            // Limpar cache
            Cache::forget("allowed_ips_{$user->username}");

            Log::info('[INTEGRATION] IP removido', [
                'user_id' => $user->username,
                'ip_removed' => $ip,
                'by_ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'IP removido com sucesso',
                'data' => [
                    'ips' => \App\Traits\IPManagementTrait::getAllowedIPs($userRefreshed)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('[INTEGRATION] Erro ao remover IP', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao remover IP'
            ], 500);
        }
    }

    /**
     * Criar credenciais para o usuário
     */
    private function createCredentials(User $user): UsersKey
    {
        $token = Str::uuid()->toString();
        $secret = Str::uuid()->toString();
        
        $userKeys = UsersKey::create([
            'user_id' => $user->username,
            'token' => $token,
            'secret' => $secret,
            'status' => 1
        ]);

        // Atualizar cliente_id no usuário
        User::where('id', $user->id)->update(['cliente_id' => $token]);

        Log::info('[INTEGRATION] Credenciais criadas automaticamente', [
            'user_id' => $user->username
        ]);

        return $userKeys;
    }
}

