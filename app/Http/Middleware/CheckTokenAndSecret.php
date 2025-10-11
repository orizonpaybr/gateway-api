<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\UsersKey;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;

class CheckTokenAndSecret
{
    public function handle(Request $request, Closure $next)
    {
        // Pegue o token e secret do corpo da requisição ou query parameters
        $token = $request->input('token') ?: $request->query('token');
        $secret = $request->input('secret') ?: $request->query('secret');

        // Verifique se ambos os parâmetros token e secret foram enviados
        if (!$token || !$secret) {
            return Response::json([
                'error' => 'Token ou Secret ausentes',
                'message' => 'Você precisa fornecer tanto o token quanto o secret.'
            ], 400); // Retorna um erro 400 se os parâmetros não forem fornecidos
        }

        // Verifique se existe um usuário com esse token e secret
        $chaves = UsersKey::where('token', $token)->where('secret', $secret)->first();
        
        // Log de segurança (sem expor dados sensíveis)
        Log::info('CheckTokenAndSecret - Tentativa de autenticação', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'token_exists' => !is_null($chaves),
            'timestamp' => now()
        ]);
        
        // Se o usuário não for encontrado, retorna um erro
        if (!$chaves) {
            Log::warning('CheckTokenAndSecret - Credenciais inválidas', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()
            ]);
            return Response::json([
                'status' => "error",
                'message' => 'Token ou Secret inválidos'
            ], 401); // Retorna um erro 401 se o token ou secret não forem válidos
        }

        $user = User::where('username', $chaves->user_id)->first();
        
        // Log de autenticação bem-sucedida (sem dados sensíveis)
        if ($user) {
            Log::info('CheckTokenAndSecret - Autenticação bem-sucedida', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
                'timestamp' => now()
            ]);
        }
        
        // Verificar status apenas para endpoints que precisam de aprovação
        // Permitir 2FA mesmo para usuários pendentes
        $currentPath = $request->path();
        $allowedPathsForPending = ['api/2fa/status', 'api/2fa/enable', 'api/2fa/verify', 'api/2fa/disable'];
        
        $isAllowedForPending = false;
        foreach ($allowedPathsForPending as $path) {
            if (str_contains($currentPath, $path)) {
                $isAllowedForPending = true;
                break;
            }
        }
        
        if($user->status != 1 && !$isAllowedForPending){
            return Response::json([
                'status' => "pending_approval",
                'message' => 'Usuário com conta pendente de aprovação.'
            ], 403)->header('Access-Control-Allow-Origin', '*');
        }
        
        // Se o usuário for encontrado, defina o usuário na requisição usando setUserResolver
        $request->setUserResolver(function() use ($user) {
            return $user;
        });

        // Também injeta o usuário diretamente no request para facilitar acesso nos controllers
        $request->merge(['user_auth' => $user]);

        // Prossiga com a requisição
        return $next($request);
    }
}
