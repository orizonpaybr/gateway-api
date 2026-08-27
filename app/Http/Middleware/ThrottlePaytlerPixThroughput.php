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
 * Respeita o limite de TPS da PAYTLER apenas quando o PIX padrão do usuário é paytler.
 * Espelha ThrottleSimpayPixThroughput.
 */
class ThrottlePaytlerPixThroughput
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        $ref = Helper::adquirenteDefault($user->username, 'pix');
        $provider = app(PixAcquirerManager::class)->resolve($ref)->getReference();
        if ($provider !== 'paytler') {
            return $next($request);
        }

        $perSecond = max(1, (int) config('paytler.rate_limit_per_second', 500));
        $key = 'paytler-pix-throughput:'.$user->username;

        $response = RateLimiter::attempt($key, $perSecond, function () use ($next, $request) {
            return $next($request);
        }, 1);

        if ($response === false) {
            return response()->json([
                'status' => 'error',
                'message' => 'Limite de requisições PIX (PAYTLER) excedido. Aguarde um instante e tente novamente.',
            ], 429);
        }

        return $response;
    }
}
