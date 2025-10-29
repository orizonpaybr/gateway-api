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
            return [$frontendUrl];
        }

        // Em desenvolvimento, permitir localhost em várias portas
        $origins = [$frontendUrl];
        
        // Adicionar variações comuns de localhost
        if (str_contains($frontendUrl, 'localhost')) {
            $origins[] = 'http://localhost:3000';
            $origins[] = 'http://localhost:3001';
            $origins[] = 'http://127.0.0.1:3000';
            $origins[] = 'http://127.0.0.1:3001';
        }

        return array_unique($origins);
    }

    /**
     * Verificar se a origem é permitida
     */
    private function isOriginAllowed(string $origin, array $allowedOrigins): bool
    {
        if (empty($origin)) {
            return false;
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
        $originHeader = '*';
        
        // Se a origem é permitida, usar ela no header
        if ($origin && $this->isOriginAllowed($origin, $allowedOrigins)) {
            $originHeader = $origin;
        }

        return response('', 200)
            ->header('Access-Control-Allow-Origin', $originHeader)
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
            ->header('Access-Control-Allow-Credentials', 'true')
            ->header('Access-Control-Max-Age', '86400'); // Cache por 24 horas
    }

    /**
     * Adicionar headers CORS na resposta
     */
    private function addCorsHeaders(Response $response, array $allowedOrigins, ?string $origin): Response
    {
        // Se não há origem na requisição, não adicionar headers CORS
        if (empty($origin)) {
            return $response;
        }

        // Se a origem é permitida, adicionar header com a origem específica
        if ($this->isOriginAllowed($origin, $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        } else {
            // Em produção, rejeitar origens não permitidas (log para auditoria)
            if (app()->environment('production')) {
                Log::warning('[CORS] Origem não permitida', [
                    'origin' => $origin,
                    'allowed_origins' => $allowedOrigins,
                    'ip' => request()->ip(),
                ]);
            }
        }

        return $response;
    }
}
