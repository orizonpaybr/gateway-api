<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limit por usuário autenticado nas rotas de configuração/verificação 2FA.
 */
class ThrottleTwoFactorAttempts
{
    public function handle(Request $request, Closure $next, string $maxAttempts = '10', string $decaySeconds = '60'): Response
    {
        $user = $request->user() ?? $request->user_auth;
        $subject = $user?->id ?? $request->ip();
        $key = '2fa-setup|'.$subject;
        $max = max(1, (int) $maxAttempts);
        $decay = max(1, (int) $decaySeconds);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'success' => false,
                'message' => 'Muitas tentativas. Aguarde antes de tentar novamente.',
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        RateLimiter::hit($key, $decay);

        $response = $next($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            RateLimiter::clear($key);
        }

        return $response;
    }
}
