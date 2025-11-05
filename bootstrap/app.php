<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append([
            \App\Http\Middleware\AtualizarSaldosClientes::class,
        ]);
        $middleware->validateCsrfTokens([
             '/cashtime/*',
             '/mercadopago/*',
             '/efi/*',
             '/pagarme/*',
             '/xgate/*',
             '/witetec/*',
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
            'check.auth' => \App\Http\Middleware\AuthMiddleware::class,
            'security' => \App\Http\Middleware\SecurityMiddleware::class,
            'validate.webhook' => \App\Http\Middleware\ValidateWebhook::class,
            'check.allowed.ip' => \App\Http\Middleware\CheckAllowedIP::class,
            'check.pin' => \App\Http\Middleware\CheckPin::class,
            'secure.cors' => \App\Http\Middleware\SecureCors::class,
        ]);
        
        // Aplicar middleware de segurança globalmente
        $middleware->append([
            \App\Http\Middleware\SecurityMiddleware::class,
        ]);
        
        // Aplicar middleware de otimização de assets globalmente (primeira execução)
        $middleware->prepend([
            \App\Http\Middleware\AssetOptimizerMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
