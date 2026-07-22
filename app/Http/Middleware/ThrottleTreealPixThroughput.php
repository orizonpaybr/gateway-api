<?php

namespace App\Http\Middleware;

use App\Helpers\Helper;
use App\Models\User;
use App\Services\PixAcquirer\PixAcquirerManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Respeita o limite informado pela TREEAL (300 TPS) quando o PIX padrão do usuário é treeal.
 *
 * Compara pelo `provider`, não pela `referencia` da nominal (ver ThrottleFluxPaymentsPixThroughput).
 */
class ThrottleTreealPixThroughput
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        $ref = Helper::adquirenteDefault($user->username, 'pix');
        $provider = app(PixAcquirerManager::class)->resolve($ref)->getReference();
        if ($provider !== 'treeal') {
            return $next($request);
        }

        $perSecond = max(1, (int) config('treeal.rate_limit_per_second', 300));
        $key = 'treeal-pix-throughput:'.$user->username;

        $response = RateLimiter::attempt($key, $perSecond, function () use ($next, $request) {
            return $next($request);
        }, 1);

        if ($response === false) {
            return response()->json([
                'status' => 'error',
                'message' => 'Limite de requisições PIX (TREEAL) excedido. Aguarde um instante e tente novamente.',
            ], 429);
        }

        return $response;
    }
}
