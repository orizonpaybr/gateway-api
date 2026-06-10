<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\UsersKey;
use App\Constants\UserStatus;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;

class CheckTokenAndSecret
{
    public function handle(Request $request, Closure $next)
    {
        // Token e secret: body, query ou headers (api-token / api-secret)
        $token = $request->input('token')
            ?: $request->query('token')
            ?: $request->header('api_token')
            ?: $request->header('api-token');
        $secret = $request->input('secret')
            ?: $request->query('secret')
            ?: $request->header('api_secret')
            ?: $request->header('api-secret');

        // Verifique se ambos os parâmetros token e secret foram enviados
        if (!$token || !$secret) {
            Log::warning('CheckTokenAndSecret - Token ou Secret ausentes', [
                'ip' => $request->ip(),
                'has_body_token' => !is_null($request->input('token')),
                'has_query_token' => !is_null($request->query('token')),
                'has_header_api_token' => !is_null($request->header('api_token')),
                'has_header_api_hyphen_token' => !is_null($request->header('api-token')),
                'has_body_secret' => !is_null($request->input('secret')),
                'has_query_secret' => !is_null($request->query('secret')),
                'has_header_api_secret' => !is_null($request->header('api_secret')),
                'has_header_api_hyphen_secret' => !is_null($request->header('api-secret')),
                'all_headers' => $request->headers->all(),
            ]);
            return Response::json([
                'error' => 'Token ou Secret ausentes',
                'message' => 'Você precisa fornecer tanto o token quanto o secret.'
            ], 400);
        }

        // Buscar chaves usando o novo método otimizado (suporta criptografia)
        $chaves = UsersKey::findByCredentials($token, $secret);
        
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
            ], 401);
        }

        $user = User::where('username', $chaves->user_id)->first();
        
        if (!$user) {
            Log::warning('CheckTokenAndSecret - Usuário não encontrado para chave válida', [
                'user_id' => $chaves->user_id,
                'ip' => $request->ip(),
            ]);
            return Response::json([
                'status' => "error",
                'message' => 'Usuário não encontrado'
            ], 401);
        }
        
        // Log de autenticação bem-sucedida (sem dados sensíveis)
        Log::info('CheckTokenAndSecret - Autenticação bem-sucedida', [
            'user_id' => $user->id,
            'ip' => $request->ip(),
            'timestamp' => now()
        ]);
        
        // Bloquear quem não pode usar a API: inativo, pendente ou banido (apenas ACTIVE pode)
        if ($user->status != UserStatus::ACTIVE || ($user->banido ?? false)) {
            $message = $user->status == UserStatus::PENDING
                ? 'Sua conta está aguardando aprovação. Você poderá acessar o dashboard após aprovação pelo administrador.'
                : 'Conta inativa ou bloqueada. Entre em contato com o suporte.';
            return Response::json([
                'status' => 'error',
                'message' => $message
            ], 403);
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
