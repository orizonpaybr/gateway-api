<?php

namespace Tests\Unit\Services\FluxPayments;

use App\Models\Adquirente;
use App\Models\SolicitacoesCashOut;
use App\Services\FluxPayments\FluxPaymentsCashOutOutcomeService;
use App\Services\FluxPayments\FluxPaymentsPixAcquirerService;
use App\Services\PixAcquirer\PixAcquirerManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FluxPaymentsResolveAcquirerForPayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_usa_adquirente_ref_da_nominal(): void
    {
        Adquirente::create([
            'adquirente' => 'FluxPayments (Santa)',
            'status' => true,
            'url' => 'https://api.fluxpaymentss.com',
            'referencia' => 'fluxpayments_santa',
            'provider' => 'fluxpayments',
            'credentials' => [
                'api_key' => 'santa_key',
                'public_key' => 'santa_public',
            ],
            'is_default' => false,
        ]);

        app(PixAcquirerManager::class)->register('fluxpayments', FluxPaymentsPixAcquirerService::class);

        $payout = new SolicitacoesCashOut([
            'user_id' => 'oincash',
            'executor_ordem' => 'fluxpayments',
            'adquirente_ref' => 'fluxpayments_santa',
            'idTransaction' => 'txn-1',
            'status' => 'PROCESSING',
        ]);

        $resolved = FluxPaymentsCashOutOutcomeService::resolveAcquirerForPayout($payout);

        $this->assertInstanceOf(FluxPaymentsPixAcquirerService::class, $resolved);
        $this->assertNotSame(app(FluxPaymentsPixAcquirerService::class), $resolved);
    }

    public function test_resolve_sem_adquirente_ref_usa_familia_fluxpayments(): void
    {
        $payout = new SolicitacoesCashOut([
            'user_id' => 'oincash',
            'executor_ordem' => 'fluxpayments',
            'adquirente_ref' => null,
            'idTransaction' => 'txn-2',
            'status' => 'PROCESSING',
        ]);

        $resolved = FluxPaymentsCashOutOutcomeService::resolveAcquirerForPayout($payout);

        $this->assertInstanceOf(FluxPaymentsPixAcquirerService::class, $resolved);
    }
}
