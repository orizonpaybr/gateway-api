<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware CORS seguro baseado em variáveis de ambiente
 * 
 * Em produção, permite apenas origens configuradas via FRONTEND_URL.
 * Em desenvolvimento, permite localhost para facilitar testes.
 * 
 * Configuração (.env):
 * FRONTEND_URL=http://localhost:3000 (desenvolvimento)
 * FRONTEND_URL=https://app.orizon.com (produção)
 * 
 * IMPORTANTE: Em produção, NUNCA usar Access-Control-Allow-Origin: *
 */
class SecureCors
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowedOrigins = $this->getAllowedOrigins();
        $origin = $request->headers->get('Origin');

        // Se é uma requisição OPTIONS (preflight), retornar resposta imediatamente
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflight($allowedOrigins, $origin);
        }

        $response = $next($request);

        // Adicionar headers CORS na resposta
        return $this->addCorsHeaders($response, $allowedOrigins, $origin);
    }

    /**
     * Obter origens permitidas baseado em variáveis de ambiente
     */
    private function getAllowedOrigins(): array
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        
        // Em produção, usar apenas a URL configurada
        if (app()->environment('production')) {
            return array_filter([$frontendUrl]);
        }

        // Em desenvolvimento, permitir localhost em várias portas
        $origins = [$frontendUrl];
        
        // Adicionar variações comuns de localhost apenas em dev
        $origins[] = 'http://localhost:3000';
        $origins[] = 'http://localhost:3001';
        $origins[] = 'http://localhost:5173'; // Vite default
        $origins[] = 'http://127.0.0.1:3000';
        $origins[] = 'http://127.0.0.1:3001';
        $origins[] = 'http://127.0.0.1:5173';

        return array_unique(array_filter($origins));
    }

    /**
     * Verificar se a origem é permitida
     */
    private function isOriginAllowed(?string $origin, array $allowedOrigins): bool
    {
        if (empty($origin)) {
            // Requisições sem Origin (ex: Postman, curl) - permitir em dev
            return !app()->environment('production');
        }

        foreach ($allowedOrigins as $allowed) {
            if ($origin === $allowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lidar com requisições OPTIONS (preflight)
     */
    private function handlePreflight(array $allowedOrigins, ?string $origin): Response
    {
        // Verificar se a origem é permitida
        if (!$this->isOriginAllowed($origin, $allowedOrigins)) {
            // Em produção, retornar 403 para origens não permitidas
            if (app()->environment('production')) {
                Log::warning('[CORS] Preflight rejeitado - Origem não permitida', [
                    'origin' => $origin,
                    'allowed_origins' => $allowedOrigins,
                    'ip' => request()->ip(),
                ]);
                
                return response()->json([
                    'error' => 'Origin not allowed'
                ], 403);
            }
        }

        // Usar a origem específica, nunca '*' em produção
        $originHeader = $origin ?: (app()->environment('production') ? '' : '*');
        
        // Se não há origem válida em produção, não adicionar header
        if (app()->environment('production') && empty($origin)) {
            return response('', 200)
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept')
                ->header('Access-Control-Max-Age', '86400');
        }

        return response('', 200)
            ->header('Access-Control-Allow-Origin', $originHeader)
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept')
            ->header('Access-Control-Allow-Credentials', 'true')
            ->header('Access-Control-Max-Age', '86400'); // Cache por 24 horas
    }

    /**
     * Adicionar headers CORS na resposta
     */
    private function addCorsHeaders(Response $response, array $allowedOrigins, ?string $origin): Response
    {
        // Se não há origem na requisição em produção, não adicionar headers CORS
        if (app()->environment('production') && empty($origin)) {
            return $response;
        }

        // Se a origem é permitida, adicionar header com a origem específica
        if ($this->isOriginAllowed($origin, $allowedOrigins)) {
            // Usar origem específica, nunca '*'
            $originToUse = $origin ?: (app()->environment('production') ? '' : '*');
            
            if (!empty($originToUse)) {
                $response->headers->set('Access-Control-Allow-Origin', $originToUse);
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
            }
        } else {
            // Em produção, logar origens não permitidas
            if (app()->environment('production') && !empty($origin)) {
                Log::warning('[CORS] Origem não permitida', [
                    'origin' => $origin,
                    'allowed_origins' => $allowedOrigins,
                    'ip' => request()->ip(),
                    'path' => request()->path(),
                ]);
            }
        }

        return $response;
    }
}
