<?php

namespace App\Providers;

use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Observers\SolicitacoesCashOutObserver;
use App\Observers\SolicitacoesObserver;
use App\Observers\UserObserver;
use App\Services\Fyhub\FyhubAuthService;
use App\Services\Fyhub\FyhubPixAcquirerService;
use App\Services\FyhubContas\FyhubContasAccountService;
use App\Services\FyhubContas\FyhubContasApiClient;
use App\Services\FyhubContas\FyhubContasAuthService;
use App\Services\FyhubContas\FyhubContasPixOutService;
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

        $this->app->singleton(FyhubAuthService::class);

        $this->app->singleton(FyhubContasAuthService::class);

        $this->app->singleton(FyhubContasApiClient::class, function ($app) {
            return new FyhubContasApiClient($app->make(FyhubContasAuthService::class));
        });

        $this->app->singleton(FyhubContasPixOutService::class, function ($app) {
            return new FyhubContasPixOutService($app->make(FyhubContasApiClient::class));
        });

        $this->app->singleton(FyhubContasAccountService::class, function ($app) {
            return new FyhubContasAccountService($app->make(FyhubContasApiClient::class));
        });

        $this->app->singleton(FyhubPixAcquirerService::class, function ($app) {
            return new FyhubPixAcquirerService(
                $app->make(FyhubAuthService::class),
                $app->make(FyhubContasPixOutService::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make(PixAcquirerManager::class)
            ->register('simpay', SimpayPixAcquirerService::class);

        $this->app->make(PixAcquirerManager::class)
            ->register('fyhub', FyhubPixAcquirerService::class);

        // Registrar Observers para monitorar mudanças de status
        Solicitacoes::observe(SolicitacoesObserver::class);
        SolicitacoesCashOut::observe(SolicitacoesCashOutObserver::class);
        User::observe(UserObserver::class);
    }
}
