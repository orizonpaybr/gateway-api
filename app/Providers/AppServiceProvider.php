<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Observers\SolicitacoesObserver;
use App\Observers\SolicitacoesCashOutObserver;
use App\Observers\UserObserver;
use App\Services\PixAcquirer\PixAcquirerManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PixAcquirerManager::class, function () {
            return new PixAcquirerManager();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void 
    {
        // Registrar Observers para monitorar mudanças de status
        Solicitacoes::observe(SolicitacoesObserver::class);
        SolicitacoesCashOut::observe(SolicitacoesCashOutObserver::class);
        User::observe(UserObserver::class);
    }
}
