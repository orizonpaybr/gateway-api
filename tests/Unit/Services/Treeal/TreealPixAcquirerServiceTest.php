<?php

namespace Tests\Unit\Services\Treeal;

use App\Services\PixAcquirer\PixAcquirerManager;
use App\Services\Treeal\TreealAuthService;
use App\Services\Treeal\TreealMtlsOptions;
use App\Services\Treeal\TreealPixAcquirerService;
use App\Services\TreealContas\TreealContasAuthService;
use Tests\TestCase;

class TreealPixAcquirerServiceTest extends TestCase
{
    private string $certPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->certPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'treeal-acquirer-test.pfx';
        file_put_contents($this->certPath, 'pfx');
    }

    protected function tearDown(): void
    {
        if (is_file($this->certPath)) {
            @unlink($this->certPath);
        }

        parent::tearDown();
    }

    private function service(): TreealPixAcquirerService
    {
        return new TreealPixAcquirerService(
            $this->createMock(TreealAuthService::class),
            $this->createMock(TreealContasAuthService::class),
        );
    }

    public function test_get_reference_returns_treeal(): void
    {
        $this->assertSame('treeal', $this->service()->getReference());
    }

    public function test_is_active_when_qr_config_complete(): void
    {
        config([
            'treeal.client_id' => 'id',
            'treeal.client_secret' => 'secret',
            'treeal.base_url' => 'https://treeal.test',
            'treeal.pix_key' => 'pix-key',
            'treeal.cert_format' => 'pfx',
            'treeal.cert_pfx_path' => $this->certPath,
        ]);

        $this->assertTrue($this->service()->isActive());
    }

    public function test_is_inactive_without_credentials_or_certificate(): void
    {
        config([
            'treeal.client_id' => '',
            'treeal.client_secret' => '',
            'treeal.base_url' => '',
            'treeal.pix_key' => '',
            'treeal.cert_pfx_path' => '',
        ]);

        $this->assertFalse($this->service()->isActive());
    }

    public function test_operational_methods_return_not_implemented_message(): void
    {
        $service = $this->service();
        $message = 'Treeal: operação não implementada — aguardando documentação';

        $this->assertSame(['success' => false, 'message' => $message], $service->createCharge(10.0, []));
        $this->assertSame(['success' => false, 'message' => $message], $service->createPayout(10.0, 'key', 'email'));
        $this->assertSame(['success' => false, 'message' => $message], $service->getChargeStatus('txid'));
        $this->assertSame(['success' => false, 'message' => $message], $service->getPayoutStatus('txid'));
        $this->assertSame(['success' => false, 'message' => $message], $service->createRefund('txid', 1.0, 'reason'));
    }

    public function test_map_payout_status_from_treeal_documentation(): void
    {
        $service = $this->service();

        $this->assertSame('COMPLETED', $service->mapPayoutStatus('LIQUIDATED'));
        $this->assertSame('PROCESSING', $service->mapPayoutStatus('PROCESSING'));
        $this->assertSame('PROCESSING', $service->mapPayoutStatus('ON_QUEUE'));
        $this->assertSame('PROCESSING', $service->mapPayoutStatus('WAITING_CONFIRMATION'));
        $this->assertSame('PROCESSING', $service->mapPayoutStatus('WAITING_SETTLEMENTCORE'));
        $this->assertSame('CANCELLED', $service->mapPayoutStatus('CANCELED'));
        $this->assertSame('REFUNDED', $service->mapPayoutStatus('REFUNDED'));
        $this->assertSame('REFUNDED', $service->mapPayoutStatus('PARTIALLY REFUNDED'));
    }

    public function test_pix_acquirer_manager_resolves_treeal(): void
    {
        $resolved = app(PixAcquirerManager::class)->resolve('treeal');

        $this->assertInstanceOf(TreealPixAcquirerService::class, $resolved);
        $this->assertSame('treeal', $resolved->getReference());
    }
}
