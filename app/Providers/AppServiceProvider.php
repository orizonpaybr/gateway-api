<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Observers\SolicitacoesObserver;
use App\Observers\SolicitacoesCashOutObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void {}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void 
    {
        // Registrar Observers para monitorar mudanças de status
        Solicitacoes::observe(SolicitacoesObserver::class);
        SolicitacoesCashOut::observe(SolicitacoesCashOutObserver::class);
    }
}
