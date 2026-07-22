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
 * Respeita o limite informado pela FluxPayments (300 TPS) quando o PIX padrão do usuário é fluxpayments.
 *
 * Compara pelo `provider` (família do serviço), não pela `referencia` da nominal:
 * várias nominais (contas com credenciais próprias) podem resolver para o
 * mesmo provider fluxpayments, e todas devem respeitar o mesmo limite.
 */
class ThrottleFluxPaymentsPixThroughput
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        $ref = Helper::adquirenteDefault($user->username, 'pix');
        $provider = app(PixAcquirerManager::class)->resolve($ref)->getReference();
        if ($provider !== 'fluxpayments') {
            return $next($request);
        }

        $perSecond = max(1, (int) config('fluxpayments.rate_limit_per_second', 300));
        $key = 'fluxpayments-pix-throughput:'.$user->username;

        $response = RateLimiter::attempt($key, $perSecond, function () use ($next, $request) {
            return $next($request);
        }, 1);

        if ($response === false) {
            return response()->json([
                'status' => 'error',
                'message' => 'Limite de requisições PIX (FluxPayments) excedido. Aguarde um instante e tente novamente.',
            ], 429);
        }

        return $response;
    }
}
