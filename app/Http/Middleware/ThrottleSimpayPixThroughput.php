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
 * Respeita o limite informado pela SIMPAY (500 TPS) apenas quando o PIX padrão
 * do usuário é simpay. Compara pelo `provider` (ver ThrottleFyhubPixThroughput).
 */
class ThrottleSimpayPixThroughput
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        $ref = Helper::adquirenteDefault($user->username, 'pix');
        $provider = app(PixAcquirerManager::class)->resolve($ref)->getReference();
        if ($provider !== 'simpay') {
            return $next($request);
        }

        $perSecond = max(1, (int) config('simpay.rate_limit_per_second', 500));
        $key = 'simpay-pix-throughput:'.$user->username;

        $response = RateLimiter::attempt($key, $perSecond, function () use ($next, $request) {
            return $next($request);
        }, 1);

        if ($response === false) {
            return response()->json([
                'status' => 'error',
                'message' => 'Limite de requisições PIX (SIMPAY) excedido. Aguarde um instante e tente novamente.',
            ], 429);
        }

        return $response;
    }
}
