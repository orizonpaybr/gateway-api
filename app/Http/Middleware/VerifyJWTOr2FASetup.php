<?php

namespace App\Http\Middleware;

use App\Constants\UserStatus;
use App\Models\User;
use App\Services\JWTService;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Aceita JWT completo ou token temporário de login (setup 2FA antes do acesso total).
 */
class VerifyJWTOr2FASetup
{
    public function __construct(
        private JWTService $jwtService,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        try {
            $token = $request->bearerToken();

            if (! $token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token não fornecido',
                ], 401);
            }

            $decoded = $this->jwtService->validateToken($token);

            if (! $decoded) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token inválido ou expirado',
                ], 401);
            }

            $isTemp = isset($decoded->temp) && $decoded->temp === true;

            if ($isTemp && ! in_array($decoded->purpose ?? '', ['2fa_setup'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token temporário inválido para esta operação',
                ], 401);
            }

            $userId = $decoded->sub ?? null;

            if (! $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token inválido',
                ], 401);
            }

            $user = User::where('username', $userId)->first();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não encontrado',
                ], 401);
            }

            if ($user->status != UserStatus::ACTIVE || ($user->banido ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conta inativa ou bloqueada',
                ], 403);
            }

            $request->setUserResolver(fn () => $user);
            $request->merge([
                'user_auth' => $user,
                'jwt_temp' => $isTemp,
            ]);

            return $next($request);
        } catch (Exception $e) {
            Log::error('VerifyJWTOr2FASetup - erro', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao verificar autenticação',
            ], 500);
        }
    }
}
