<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para garantir que o usuário é admin (permission = 3)
 */
class EnsureAdminPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? $request->user_auth;
        
        if (!$user || $user->permission != \App\Constants\UserPermission::ADMIN) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado'
            ], 403)->header('Access-Control-Allow-Origin', '*');
        }
        
        return $next($request);
    }
}

