<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para logar queries lentas
 * 
 * Útil para monitoramento de performance em produção
 * 
 * Para ativar, adicionar no Kernel.php:
 * protected $middleware = [
 *     \App\Http\Middleware\LogSlowQueries::class,
 * ];
 */
class LogSlowQueries
{
    /**
     * Threshold em milissegundos para considerar query lenta
     */
    private const SLOW_QUERY_THRESHOLD_MS = 1000; // 1 segundo

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Registrar listener para queries lentas
        DB::listen(function ($query) {
            if ($query->time > self::SLOW_QUERY_THRESHOLD_MS) {
                Log::warning('Query lenta detectada', [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'time_ms' => $query->time,
                    'url' => request()->fullUrl(),
                    'method' => request()->method(),
                ]);
            }
        });

        return $next($request);
    }
}

