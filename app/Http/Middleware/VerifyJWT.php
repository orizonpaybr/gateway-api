<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;
use App\Services\JWTService;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;
use Exception;

class VerifyJWT
{
    private JWTService $jwtService;
    
    public function __construct(JWTService $jwtService)
    {
        $this->jwtService = $jwtService;
    }
    
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
                    'ip' => $request->ip(),
                ]);
                return Response::json([
                    'success' => false,
                    'message' => 'Token não fornecido'
                ], 401);
            }

            // Validar o token JWT usando o serviço
            $decoded = $this->jwtService->validateToken($token);
            
            if (!$decoded) {
                Log::warning('VerifyJWT - Token inválido ou expirado', [
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                ]);
                return Response::json([
                    'success' => false,
                    'message' => 'Token inválido ou expirado'
                ], 401);
            }
            
            // Verificar se não é um token temporário (2FA)
            if (isset($decoded->temp) && $decoded->temp === true) {
                Log::warning('VerifyJWT - Tentativa de uso de token temporário', [
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                ]);
                return Response::json([
                    'success' => false,
                    'message' => 'Token temporário não é válido para esta operação'
                ], 401);
            }
            
            // Extrair user_id do token (claim 'sub')
            $userId = $decoded->sub ?? null;
            
            if (!$userId) {
                Log::warning('VerifyJWT - Token sem user_id', [
                    'ip' => $request->ip(),
                ]);
                return Response::json([
                    'success' => false,
                    'message' => 'Token inválido'
                ], 401);
            }
            
            Log::info('VerifyJWT - Token decodificado', [
                'user_id' => $userId,
                'expires_at' => isset($decoded->exp) ? date('Y-m-d H:i:s', $decoded->exp) : null,
            ]);

            // Buscar o usuário
            $user = User::where('username', $userId)->first();
            
            if (!$user) {
                Log::warning('VerifyJWT - Usuário não encontrado', [
                    'user_id' => $userId,
                    'ip' => $request->ip(),
                ]);
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
                Log::warning('VerifyJWT - Conta inativa ou bloqueada', [
                    'user_id' => $user->id,
                    'status' => $user->status,
                    'banido' => $user->banido,
                ]);
                return Response::json([
                    'success' => false,
                    'message' => 'Conta inativa ou bloqueada. Entre em contato com o suporte.'
                ], 403);
            }

            // Definir o usuário na requisição
            $request->setUserResolver(function() use ($user) {
                return $user;
            });

            // Também injeta o usuário diretamente no request para facilitar acesso nos controllers
            $request->merge(['user_auth' => $user]);

            // Prosseguir com a requisição
            return $next($request);
            
        } catch (Exception $e) {
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
