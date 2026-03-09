<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garante que a URL de callback do webhook seja acessada apenas via HTTPS.
 *
 * Conformidade com documentação de webhooks: "A URL de retorno opera exclusivamente
 * no protocolo HTTPS?". Em produção rejeita requisições HTTP a esta rota.
 */
class EnsureWebhookHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isSecure($request)) {
            return $next($request);
        }

        Log::warning('[Webhook HTTPS] Requisição HTTP rejeitada (apenas HTTPS permitido)', [
            'path'   => $request->path(),
            'ip'     => $request->ip(),
            'scheme' => $request->getScheme(),
        ]);

        return response()->json([
            'status'  => 'error',
            'message' => 'Webhook callback must be called over HTTPS',
        ], 403);
    }

    private function isSecure(Request $request): bool
    {
        if ($request->secure()) {
            return true;
        }

        // Atrás de proxy/load balancer (ex.: nginx, Cloudflare)
        $proto = $request->header('X-Forwarded-Proto');
        if ($proto !== null && strtolower($proto) === 'https') {
            return true;
        }

        return false;
    }
}
