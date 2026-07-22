<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\ThrottleFluxPaymentsPixThroughput;
use App\Models\Adquirente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * O throttle da FluxPayments precisa disparar para QUALQUER nominal cujo
 * provider seja fluxpayments, não só para a linha legada cuja referencia
 * literalmente se chama "fluxpayments". Regressão: comparar pela referencia
 * fazia o limite de 300 TPS parar de ser aplicado ao trocar o default global
 * para uma nominal nova (referencia diferente, mesmo provider).
 */
class ThrottleFluxPaymentsPixThroughputTest extends TestCase
{
    use RefreshDatabase;

    public function test_aplica_throttle_quando_default_e_nominal_com_referencia_diferente(): void
    {
        Adquirente::query()->update(['is_default' => false]);

        Adquirente::create([
            'adquirente' => 'FluxPayments (Nominal 2)',
            'status' => true,
            'url' => 'https://api.fluxpaymentss.com',
            'referencia' => 'fluxpayments-nominal-2',
            'provider' => 'fluxpayments',
            'is_default' => true,
        ]);

        $user = User::factory()->create();

        config(['fluxpayments.rate_limit_per_second' => 1]);

        $middleware = new ThrottleFluxPaymentsPixThroughput;
        $next = fn ($request) => response()->json(['ok' => true]);

        $request = Request::create('/qualquer-rota-pix', 'POST');
        $request->setUserResolver(fn () => $user);

        $first = $middleware->handle($request, $next);
        $second = $middleware->handle($request, $next);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(429, $second->getStatusCode());
    }
}
