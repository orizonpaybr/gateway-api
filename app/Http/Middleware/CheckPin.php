<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Traits\PinManagementTrait;

class CheckPin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        // Verificar se o usuário está autenticado via token (adicionado pelo CheckTokenAndSecret)
        if (!$request->has('user') || !$request->user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado'
            ], 401);
        }

        $user = $request->user;

        // Verificar se o usuário tem PIN ativo
        if (!PinManagementTrait::hasActivePin($user)) {
            return response()->json([
                'success' => false,
                'message' => 'PIN não configurado ou inativo'
            ], 403);
        }

        // Verificar se o PIN foi fornecido
        $pin = $request->input('pin');
        if (!$pin) {
            return response()->json([
                'success' => false,
                'message' => 'PIN é obrigatório para esta operação',
                'requires_pin' => true
            ], 400);
        }

        // Verificar se o PIN está correto
        if (!PinManagementTrait::verifyPin($user, $pin)) {
            return response()->json([
                'success' => false,
                'message' => 'PIN incorreto'
            ], 403);
        }

        return $next($request);
    }
}
