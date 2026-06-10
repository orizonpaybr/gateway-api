<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->booted(function () {
        RateLimiter::for('pix-out', function (Request $request) {
            $key = $request->input('token') ?? $request->ip();

            return Limit::perMinutes(15, 10000)->by('pix-out|'.$key);
        });

        RateLimiter::for('pix-in', function (Request $request) {
            $key = $request->input('token') ?? $request->ip();

            return Limit::perMinutes(15, 10000)->by('pix-in|'.$key);
        });

        RateLimiter::for('status-check', function (Request $request) {
            $key = $request->input('token') ?? $request->input('idTransaction') ?? $request->ip();
            $perMinute = max(30, (int) config('saque.status_check_rate_limit_per_minute', 120));

            return Limit::perMinute($perMinute)->by('status|'.$key);
        });

        RateLimiter::for('balance-check', function (Request $request) {
            $key = $request->input('token')
                ?? $request->query('token')
                ?? $request->header('api_token')
                ?? $request->header('api-token')
                ?? $request->ip();
            $perMinute = max(10, (int) config('saldo.balance_check_rate_limit_per_minute', 60));

            return Limit::perMinute($perMinute)->by('balance-check|'.$key);
        });

        RateLimiter::for('simpay-webhook', function (Request $request) {
            return Limit::perMinute((int) config('simpay.webhook_rate_limit_per_minute', 18000))
                ->by('simpay-webhook|'.$request->ip());
        });

        RateLimiter::for('simpay-api', function (Request $request) {
            $key = $request->input('token') ?? $request->ip();

            return Limit::perMinute((int) config('simpay.rate_limit_per_minute', 18000))
                ->by('simpay-api|'.$key);
        });

        RateLimiter::for('fyhub-webhook', function (Request $request) {
            return Limit::perMinute((int) config('fyhub.webhook_rate_limit_per_minute', 18000))
                ->by('fyhub-webhook|'.$request->ip());
        });

        RateLimiter::for('fyhub-contas-webhook', function (Request $request) {
            return Limit::perMinute((int) config('fyhub_contas.webhook_rate_limit_per_minute', 18000))
                ->by('fyhub-contas-webhook|'.$request->ip());
        });

        RateLimiter::for('treeal-webhook', function (Request $request) {
            return Limit::perMinute((int) config('treeal.webhook_rate_limit_per_minute', 18000))
                ->by('treeal-webhook|'.$request->ip());
        });

        RateLimiter::for('treeal-contas-webhook', function (Request $request) {
            return Limit::perMinute((int) config('treeal_contas.webhook_rate_limit_per_minute', 18000))
                ->by('treeal-contas-webhook|'.$request->ip());
        });
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append([
            \App\Http\Middleware\AtualizarSaldosClientes::class,
        ]);
        $middleware->validateCsrfTokens([
            '/pagarme/*',
            '/simpay/*',
            '/fyhub/*',
            '/treeal/*',
            '/callback',
            '/callback/*',
            '/checkout/webhook/*',
            '/api/card/webhook',
        ]);

        $middleware->alias([
            'check.token.secret' => \App\Http\Middleware\CheckTokenAndSecret::class,
            'verify.jwt' => \App\Http\Middleware\VerifyJWT::class,
            'check.admin' => \App\Http\Middleware\AdminMiddleware::class,
            'ensure.admin' => \App\Http\Middleware\EnsureAdminPermission::class,
            'ensure.admin_or_manager' => \App\Http\Middleware\EnsureAdminOrManagerPermission::class,
            'check.auth' => \App\Http\Middleware\AuthMiddleware::class,
            'security' => \App\Http\Middleware\SecurityMiddleware::class,
            'validate.webhook' => \App\Http\Middleware\ValidateWebhook::class,
            'ensure.webhook.https' => \App\Http\Middleware\EnsureWebhookHttps::class,
            'check.allowed.ip' => \App\Http\Middleware\CheckAllowedIP::class,
            'check.pin' => \App\Http\Middleware\CheckPin::class,
            'secure.cors' => \App\Http\Middleware\SecureCors::class,
            'throttle.fyhub.pix' => \App\Http\Middleware\ThrottleFyhubPixThroughput::class,
            'throttle.treeal.pix' => \App\Http\Middleware\ThrottleTreealPixThroughput::class,
            'throttle.balance.failures' => \App\Http\Middleware\ThrottleBalanceCheckFailures::class,
        ]);

        // Aplicar CORS seguro globalmente nas rotas API
        // IMPORTANTE: Nunca usar Access-Control-Allow-Origin: * em produção
        $middleware->prependToGroup('api', [
            \App\Http\Middleware\SecureCors::class,
        ]);

        // Aplicar middleware de segurança globalmente
        $middleware->append([
            \App\Http\Middleware\SecurityMiddleware::class,
        ]);

        // Middleware para logar queries lentas (monitoramento de performance)
        $middleware->append([
            \App\Http\Middleware\LogSlowQueries::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
