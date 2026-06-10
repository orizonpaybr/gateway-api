<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limita tentativas falhas consecutivas na consulta de saldo por IP.
 *
 * Após N respostas 400/401/403/500, o IP é bloqueado temporariamente (429).
 * Requisições bem-sucedidas (2xx) zeram o contador do IP.
 */
class ThrottleBalanceCheckFailures
{
    /** @var list<int> */
    private const FAILURE_STATUSES = [400, 401, 403, 500];

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->throttleKey($request);
        $maxAttempts = max(1, (int) config('saldo.balance_failure_max_attempts_per_ip', 3));

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'status' => 'error',
                'message' => 'Muitas tentativas falhas deste IP. Tente novamente mais tarde.',
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        $response = $next($request);

        if ($this->isFailureResponse($response)) {
            $decaySeconds = max(60, (int) config('saldo.balance_failure_decay_seconds', 900));
            RateLimiter::hit($key, $decaySeconds);
        } elseif ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            RateLimiter::clear($key);
        }

        return $response;
    }

    private function throttleKey(Request $request): string
    {
        return 'balance-check-fail|'.$request->ip();
    }

    private function isFailureResponse(Response $response): bool
    {
        return in_array($response->getStatusCode(), self::FAILURE_STATUSES, true);
    }
}
