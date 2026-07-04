<?php

namespace App\Http\Middleware;

use App\Services\JWTService;
use App\Services\LoginLockoutService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limit por IP e conta nas rotas de autenticação.
 * Retorna 429 antes de processar quando limites são excedidos.
 */
class ThrottleLoginFailures
{
    public function __construct(
        private LoginLockoutService $lockout,
        private JWTService $jwtService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->lockout->ipTooManyAttempts($request)) {
            $retryAfter = $this->lockout->ipRetryAfter($request);

            return response()->json([
                'success' => false,
                'message' => 'Muitas tentativas deste IP. Tente novamente mais tarde.',
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        $username = $this->resolveUsername($request);
        if ($username !== '' && $this->lockout->accountTooManyAttempts($username)) {
            $retryAfter = $this->lockout->accountRetryAfter($username);

            return response()->json([
                'success' => false,
                'message' => 'Muitas tentativas para esta conta. Tente novamente mais tarde.',
                'retry_after' => $retryAfter,
                'session_terminated' => $request->is('api/auth/verify-2fa'),
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $this->lockout->clearIpLimiter($request);
        }

        return $response;
    }

    private function resolveUsername(Request $request): string
    {
        $username = (string) $request->input('username', '');
        if ($username !== '') {
            return $username;
        }

        $tempToken = $request->input('temp_token');
        if (! is_string($tempToken) || $tempToken === '') {
            return '';
        }

        $decoded = $this->jwtService->validateToken($tempToken);
        if ($decoded && isset($decoded->sub)) {
            return (string) $decoded->sub;
        }

        return '';
    }
}
