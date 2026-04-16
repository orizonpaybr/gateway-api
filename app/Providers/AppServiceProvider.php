<?php

namespace App\Providers;

use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Observers\SolicitacoesCashOutObserver;
use App\Observers\SolicitacoesObserver;
use App\Observers\UserObserver;
use App\Services\PixAcquirer\PixAcquirerManager;
use App\Services\Simpay\SimpayAuthService;
use App\Services\Simpay\SimpayCpfService;
use App\Services\Simpay\SimpayPixAcquirerService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PixAcquirerManager::class, function () {
            return new PixAcquirerManager;
        });

        $this->app->singleton(SimpayAuthService::class);

        $this->app->singleton(SimpayCpfService::class, function ($app) {
            return new SimpayCpfService($app->make(SimpayAuthService::class));
        });

        $this->app->singleton(SimpayPixAcquirerService::class, function ($app) {
            return new SimpayPixAcquirerService($app->make(SimpayAuthService::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make(PixAcquirerManager::class)
            ->register('simpay', SimpayPixAcquirerService::class);

        // Registrar Observers para monitorar mudanças de status
        Solicitacoes::observe(SolicitacoesObserver::class);
        SolicitacoesCashOut::observe(SolicitacoesCashOutObserver::class);
        User::observe(UserObserver::class);
    }
}
