<?php

namespace App\Providers;

use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Observers\SolicitacoesCashOutObserver;
use App\Observers\SolicitacoesObserver;
use App\Observers\UserObserver;
use App\Services\FluxPayments\FluxPaymentsAuthService;
use App\Services\FluxPayments\FluxPaymentsPixAcquirerService;
use App\Services\Paya55\Paya55AuthService;
use App\Services\Paya55\Paya55PixAcquirerService;
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
use App\Services\Treeal\TreealAuthService;
use App\Services\Treeal\TreealCashOutOutcomeService;
use App\Services\Treeal\TreealPixAcquirerService;
use App\Services\Treeal\TreealWebhookRegistrationService;
use App\Services\TreealContas\TreealContasApiClient;
use App\Services\TreealContas\TreealContasAuthService;
use App\Services\TreealContas\TreealContasInfractionService;
use App\Services\TreealContas\TreealContasPixOutService;
use App\Services\TreealContas\TreealContasWebhookRegistrationService;
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

        $this->app->singleton(TreealAuthService::class);

        $this->app->singleton(TreealContasAuthService::class);

        $this->app->singleton(TreealContasApiClient::class, function ($app) {
            return new TreealContasApiClient($app->make(TreealContasAuthService::class));
        });

        $this->app->singleton(TreealContasPixOutService::class, function ($app) {
            return new TreealContasPixOutService($app->make(TreealContasApiClient::class));
        });

        $this->app->singleton(TreealContasWebhookRegistrationService::class, function ($app) {
            return new TreealContasWebhookRegistrationService(
                $app->make(TreealContasApiClient::class),
                $app->make(TreealContasAuthService::class),
            );
        });

        $this->app->singleton(TreealContasInfractionService::class, function ($app) {
            return new TreealContasInfractionService(
                $app->make(TreealContasApiClient::class),
                $app->make(TreealContasAuthService::class),
            );
        });

        $this->app->singleton(TreealCashOutOutcomeService::class);

        $this->app->singleton(TreealPixAcquirerService::class, function ($app) {
            return new TreealPixAcquirerService(
                $app->make(TreealAuthService::class),
                $app->make(TreealContasAuthService::class),
                $app->make(TreealContasPixOutService::class),
            );
        });

        $this->app->singleton(TreealWebhookRegistrationService::class, function ($app) {
            return new TreealWebhookRegistrationService($app->make(TreealAuthService::class));
        });

        $this->app->singleton(FluxPaymentsAuthService::class);

        $this->app->singleton(FluxPaymentsPixAcquirerService::class, function ($app) {
            return new FluxPaymentsPixAcquirerService($app->make(FluxPaymentsAuthService::class));
        });

        $this->app->singleton(Paya55AuthService::class);

        $this->app->singleton(Paya55PixAcquirerService::class, function ($app) {
            return new Paya55PixAcquirerService($app->make(Paya55AuthService::class));
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

        $this->app->make(PixAcquirerManager::class)
            ->register('treeal', TreealPixAcquirerService::class);

        $this->app->make(PixAcquirerManager::class)
            ->register('fluxpayments', FluxPaymentsPixAcquirerService::class);

        $this->app->make(PixAcquirerManager::class)
            ->register('paya55', Paya55PixAcquirerService::class);

        // Registrar Observers para monitorar mudanças de status
        Solicitacoes::observe(SolicitacoesObserver::class);
        SolicitacoesCashOut::observe(SolicitacoesCashOutObserver::class);
        User::observe(UserObserver::class);
    }
}
