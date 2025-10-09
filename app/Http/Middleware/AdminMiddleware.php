<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();
        
        // Log para debug
        \Log::info('AdminMiddleware - Verificando permissão', [
            'user_id' => $user->id,
            'permission' => $user->permission,
            'url' => $request->url()
        ]);

        if($user->permission != 3){
            \Log::warning('AdminMiddleware - Acesso negado', [
                'user_id' => $user->id,
                'permission' => $user->permission,
                'required_permission' => 3
            ]);
            
            return redirect()->route('dashboard')->with('error', 'Acesso negado. Permissão insuficiente.');
        }
        
        \Log::info('AdminMiddleware - Acesso permitido', [
            'user_id' => $user->id,
            'permission' => $user->permission
        ]);
        
        return $next($request);
    }
}
