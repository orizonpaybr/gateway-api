<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;
use App\Constants\UserStatus;
use App\Services\JWTService;
use Illuminate\Support\Facades\Log;
use Exception;

class VerifyJWT
{
    public function __construct(
        private JWTService $jwtService,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        try {
            $token = $request->bearerToken();

            Log::debug('VerifyJWT - Iniciando verificação', [
                'path' => $request->path(),
                'has_token' => ! empty($token),
            ]);

            if (! $token) {
                Log::warning('VerifyJWT - Token não fornecido', [
                    'path' => $request->path(),
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Token não fornecido',
                ], 401);
            }

            $decoded = $this->jwtService->validateToken($token);

            if (! $decoded) {
                Log::warning('VerifyJWT - Token inválido ou expirado', [
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Token inválido ou expirado',
                ], 401);
            }

            if (isset($decoded->temp) && $decoded->temp === true) {
                Log::warning('VerifyJWT - Tentativa de uso de token temporário', [
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Token temporário não é válido para esta operação',
                ], 401);
            }

            $userId = $decoded->sub ?? null;

            if (! $userId) {
                Log::warning('VerifyJWT - Token sem user_id', [
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Token inválido',
                ], 401);
            }

            Log::debug('VerifyJWT - Token decodificado', [
                'user_id' => $userId,
                'expires_at' => isset($decoded->exp) ? date('Y-m-d H:i:s', $decoded->exp) : null,
            ]);

            $user = User::where('username', $userId)->first();

            if (! $user) {
                Log::warning('VerifyJWT - Usuário não encontrado', [
                    'user_id' => $userId,
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado',
                ], 401);
            }

            Log::debug('VerifyJWT - Autenticação bem-sucedida', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            if ($user->status != UserStatus::ACTIVE || ($user->banido ?? false)) {
                $message = $user->status == UserStatus::PENDING
                    ? 'Sua conta está aguardando aprovação. Você poderá acessar o dashboard após aprovação pelo administrador.'
                    : 'Conta inativa ou bloqueada. Entre em contato com o suporte.';
                Log::warning('VerifyJWT - Conta não permitida para acesso', [
                    'user_id' => $user->id,
                    'status' => $user->status,
                    'banido' => $user->banido,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 403);
            }

            $request->setUserResolver(fn () => $user);
            $request->merge(['user_auth' => $user]);

            return $next($request);
        } catch (Exception $e) {
            Log::error('VerifyJWT - Erro na verificação do token', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao verificar autenticação',
            ], 500);
        }
    }
}
