<?php

namespace App\Http\Middleware;

use App\Constants\UserPermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para garantir que o usuário é admin ou gerente (permission = 3 ou 2)
 */
class EnsureAdminOrManagerPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? $request->user_auth;
        
        $isAllowed = $user && in_array($user->permission, [
            UserPermission::ADMIN,
            UserPermission::MANAGER,
        ], true);

        if (!$isAllowed) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado'
            ], 403)->header('Access-Control-Allow-Origin', '*');
        }
        
        return $next($request);
    }
}

