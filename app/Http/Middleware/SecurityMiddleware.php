<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SecurityMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Bloquear tentativas de acesso a arquivos PHP em uploads
        if ($request->is('uploads/*') && $request->getPathInfo() && 
            preg_match('/\.(php|phtml|php3|php4|php5)$/i', $request->getPathInfo())) {
            Log::warning('Tentativa de acesso a arquivo PHP bloqueada', [
                'ip' => $request->ip(),
                'path' => $request->getPathInfo(),
                'user_agent' => $request->userAgent()
            ]);
            abort(403, 'Acesso negado');
        }

        // Bloquear requests suspeitos
        $suspiciousPatterns = [
            '/eval\s*\(/i',
            '/base64_decode/i',
            '/shell_exec/i',
            '/system\s*\(/i',
            '/exec\s*\(/i',
            '/passthru/i',
            '/<\?php/i',
            '/\$_GET\[/i',
            '/\$_POST\[/i'
        ];

        $requestContent = $request->getContent();
        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $requestContent)) {
                Log::warning('Request suspeito bloqueado', [
                    'ip' => $request->ip(),
                    'pattern' => $pattern,
                    'user_agent' => $request->userAgent()
                ]);
                abort(403, 'Request inválido');
            }
        }

        $response = $next($request);
        
        // Adicionar headers de segurança
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        
        return $response;
    }
}
