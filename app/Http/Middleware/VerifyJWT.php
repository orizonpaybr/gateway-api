<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;

class VerifyJWT
{
    public function handle(Request $request, Closure $next)
    {
        try {
            // Pegar o token do cabeçalho Authorization
            $token = $request->bearerToken();
            
            Log::info('VerifyJWT - Iniciando verificação', [
                'path' => $request->path(),
                'has_token' => !empty($token),
                'token_preview' => $token ? substr($token, 0, 30) . '...' : null
            ]);
            
            // Verificar se o token foi fornecido
            if (!$token) {
                Log::warning('VerifyJWT - Token não fornecido', [
                    'path' => $request->path(),
                    'headers' => $request->headers->all()
                ]);
                return Response::json([
                    'success' => false,
                    'message' => 'Token não fornecido'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Decodificar o token JWT
            $decoded = json_decode(base64_decode($token), true);
            
            Log::info('VerifyJWT - Token decodificado', [
                'decoded_success' => !empty($decoded),
                'has_user_id' => isset($decoded['user_id']),
                'has_expires_at' => isset($decoded['expires_at']),
                'user_id' => $decoded['user_id'] ?? null,
                'expires_at' => $decoded['expires_at'] ?? null,
                'now' => now()->timestamp
            ]);
            
            // Verificar se o token é válido e não expirou
            if (!$decoded || !isset($decoded['user_id']) || !isset($decoded['expires_at']) || $decoded['expires_at'] < now()->timestamp) {
                Log::warning('VerifyJWT - Token inválido ou expirado', [
                    'decoded' => $decoded,
                    'has_user_id' => isset($decoded['user_id']),
                    'has_expires_at' => isset($decoded['expires_at']),
                    'expires_at' => $decoded['expires_at'] ?? null,
                    'now' => now()->timestamp
                ]);
                return Response::json([
                    'success' => false,
                    'message' => 'Token inválido ou expirado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            // Buscar o usuário
            $user = User::where('username', $decoded['user_id'])->first();
            
            if (!$user) {
                return Response::json([
                    'success' => false,
                    'message' => 'Usuário não encontrado'
                ], 401);
            }

            // Log de autenticação bem-sucedida (sem dados sensíveis)
            Log::info('VerifyJWT - Autenticação bem-sucedida', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
                'timestamp' => now()
            ]);

            // Bloquear apenas usuários inativos (status = 0) ou banidos
            // Usuários pendentes (status = 2) podem acessar todas as APIs (exceto integração)
            if ($user->status == 0 || ($user->banido ?? false)) {
                return Response::json([
                    'success' => false,
                    'message' => 'Conta inativa ou bloqueada. Entre em contato com o suporte.'
                ], 403)->header('Access-Control-Allow-Origin', '*');
            }

            // Definir o usuário na requisição
            $request->setUserResolver(function() use ($user) {
                return $user;
            });

            // Também injeta o usuário diretamente no request para facilitar acesso nos controllers
            $request->merge(['user_auth' => $user]);

            // Prosseguir com a requisição
            return $next($request);
            
        } catch (\Exception $e) {
            Log::error('VerifyJWT - Erro na verificação do token', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return Response::json([
                'success' => false,
                'message' => 'Erro ao verificar autenticação'
            ], 500);
        }
    }
}

