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
            return Limit::perMinutes(15, 10000)->by('pix-out|' . $key);
        });

        RateLimiter::for('pix-in', function (Request $request) {
            $key = $request->input('token') ?? $request->ip();
            return Limit::perMinutes(15, 10000)->by('pix-in|' . $key);
        });

        RateLimiter::for('status-check', function (Request $request) {
            $key = $request->input('token') ?? $request->input('idTransaction') ?? $request->ip();
            return Limit::perMinutes(15, 10000)->by('status|' . $key);
        });
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append([
            \App\Http\Middleware\AtualizarSaldosClientes::class,
        ]);
        $middleware->validateCsrfTokens([
             '/pagarme/*',
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
        
        // Aplicar middleware de otimização de assets globalmente (primeira execução)
        $middleware->prepend([
            \App\Http\Middleware\AssetOptimizerMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
