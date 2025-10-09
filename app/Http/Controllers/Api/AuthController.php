<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UsersKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use PragmaRX\Google2FA\Google2FA;

class AuthController extends Controller
{
    /**
     * Login do usuário via API
     */
    public function login(Request $request)
    {
        try {
            // Headers CORS para permitir requisições do app mobile
            $response = response();
            
            // Validar dados de entrada
            $validator = Validator::make($request->all(), [
                'username' => 'required|string',
                'password' => 'required|string',
            ]);

            if ($validator->fails()) {
                return $response->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400)->header('Access-Control-Allow-Origin', '*')
                  ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
                  ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
            }

            $username = $request->input('username');
            $password = $request->input('password');

            // Buscar usuário pelo username ou email
            $user = User::where('username', $username)
                       ->orWhere('email', $username)
                       ->first();

            if (!$user) {
                Log::warning('Tentativa de login com usuário inexistente', [
                    'username' => $username,
                    'ip' => $request->ip()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado'
                ], 401);
            }

            // Verificar senha
            if (!Hash::check($password, $user->password)) {
                Log::warning('Tentativa de login com senha incorreta', [
                    'username' => $username,
                    'ip' => $request->ip()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Senha incorreta'
                ], 401);
            }

            // Verificar se o usuário tem 2FA ativo
            if ($user->twofa_enabled && $user->twofa_secret) {
                // Gerar token temporário para verificação 2FA
                $tempToken = base64_encode(json_encode([
                    'user_id' => $user->username,
                    'temp' => true,
                    'expires_at' => now()->addMinutes(5)->timestamp
                ]));

                Log::info('Login requer verificação 2FA', [
                    'username' => $username,
                    'ip' => $request->ip()
                ]);

                return $response->json([
                    'success' => false,
                    'requires_2fa' => true,
                    'message' => 'Digite o código de 6 dígitos do seu app autenticador',
                    'temp_token' => $tempToken
                ], 200)->header('Access-Control-Allow-Origin', '*')
                  ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
                  ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
            }

            // Buscar as chaves do usuário
            $userKeys = UsersKey::where('user_id', $user->username)->first();

            if (!$userKeys) {
                Log::warning('Usuário sem chaves de API configuradas', [
                    'username' => $username,
                    'ip' => $request->ip()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário sem chaves de API configuradas'
                ], 401);
            }

            // Gerar token JWT simples (você pode usar uma biblioteca JWT real)
            $token = base64_encode(json_encode([
                'user_id' => $user->username,
                'token' => $userKeys->token,
                'secret' => $userKeys->secret,
                'expires_at' => now()->addHours(24)->timestamp
            ]));

            Log::info('Login bem-sucedido via API', [
                'username' => $username,
                'ip' => $request->ip()
            ]);

            return $response->json([
                'success' => true,
                'message' => 'Login realizado com sucesso',
                'data' => [
                    'user' => [
                        'id' => $user->username,
                        'username' => $user->username,
                        'email' => $user->email ?? '',
                        'name' => $user->name ?? $user->username,
                    ],
                    'token' => $token,
                    'api_token' => $userKeys->token,
                    'api_secret' => $userKeys->secret,
                ]
            ])->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');

        } catch (\Exception $e) {
            Log::error('Erro no login da API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500);
        }
    }

    /**
     * Verificar código 2FA
     */
    public function verify2FA(Request $request)
    {
        try {
            // Validar dados de entrada
            $validator = Validator::make($request->all(), [
                'temp_token' => 'required|string',
                'code' => 'required|string|size:6'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400)->header('Access-Control-Allow-Origin', '*');
            }

            $tempToken = $request->input('temp_token');
            $code = $request->input('code');

            // Decodificar token temporário
            $decoded = json_decode(base64_decode($tempToken), true);
            
            if (!$decoded || !isset($decoded['temp']) || !$decoded['temp'] || 
                !isset($decoded['expires_at']) || $decoded['expires_at'] < now()->timestamp) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token temporário expirado ou inválido'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Buscar usuário
            $user = User::where('username', $decoded['user_id'])->first();
            
            if (!$user || !$user->twofa_enabled || !$user->twofa_secret) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado ou 2FA não configurado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Verificar código 2FA
            $google2fa = new Google2FA();
            $valid = $google2fa->verifyKey($user->twofa_secret, $code);

            if (!$valid) {
                Log::warning('Código 2FA inválido', [
                    'username' => $user->username,
                    'ip' => $request->ip()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Código inválido'
                ], 400)->header('Access-Control-Allow-Origin', '*');
            }

            // Buscar as chaves do usuário
            $userKeys = UsersKey::where('user_id', $user->username)->first();

            if (!$userKeys) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário sem chaves de API configuradas'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Gerar token final
            $token = base64_encode(json_encode([
                'user_id' => $user->username,
                'token' => $userKeys->token,
                'secret' => $userKeys->secret,
                'expires_at' => now()->addHours(24)->timestamp
            ]));

            Log::info('Login 2FA bem-sucedido via API', [
                'username' => $user->username,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Login realizado com sucesso',
                'data' => [
                    'user' => [
                        'id' => $user->username,
                        'username' => $user->username,
                        'email' => $user->email ?? '',
                        'name' => $user->name ?? $user->username,
                    ],
                    'token' => $token,
                    'api_token' => $userKeys->token,
                    'api_secret' => $userKeys->secret,
                ]
            ])->header('Access-Control-Allow-Origin', '*')
              ->header('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
              ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');

        } catch (\Exception $e) {
            Log::error('Erro na verificação 2FA da API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Verificar token válido
     */
    public function verifyToken(Request $request)
    {
        try {
            $token = $request->bearerToken();
            
            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token não fornecido'
                ], 401);
            }

            // Decodificar token
            $decoded = json_decode(base64_decode($token), true);
            
            if (!$decoded || !isset($decoded['expires_at']) || $decoded['expires_at'] < now()->timestamp) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token expirado'
                ], 401);
            }

            // Buscar usuário
            $user = User::where('username', $decoded['user_id'])->first();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->username,
                        'username' => $user->username,
                        'email' => $user->email ?? '',
                        'name' => $user->name ?? $user->username,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro na verificação do token', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Token inválido'
            ], 401);
        }
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        // Com token simples, não há muito o que fazer no logout
        // Em uma implementação JWT real, você invalidaria o token
        
        return response()->json([
            'success' => true,
            'message' => 'Logout realizado com sucesso'
        ]);
    }
}
