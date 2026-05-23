<?php

namespace Tests\Unit\Services\Treeal;

use App\Services\PixAcquirer\PixAcquirerManager;
use App\Services\Treeal\TreealAuthService;
use App\Services\Treeal\TreealMtlsOptions;
use App\Services\Treeal\TreealPixAcquirerService;
use App\Services\TreealContas\TreealContasAuthService;
use App\Services\TreealContas\TreealContasPixOutService;
use Illuminate\Support\Facades\Http;
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

    private function service(
        ?TreealAuthService $auth = null,
        ?TreealContasPixOutService $contasPixOut = null
    ): TreealPixAcquirerService {
        $auth ??= $this->createMock(TreealAuthService::class);

        return new TreealPixAcquirerService(
            $auth,
            $this->createMock(TreealContasAuthService::class),
            $contasPixOut ?? $this->createMock(TreealContasPixOutService::class),
        );
    }

    private function configureActiveTreeal(): void
    {
        config([
            'treeal.client_id' => 'id',
            'treeal.client_secret' => 'secret',
            'treeal.base_url' => 'https://treeal.test',
            'treeal.pix_key' => '00020126580014br.gov.bcb.pix',
            'treeal.cert_format' => 'pfx',
            'treeal.cert_pfx_path' => $this->certPath,
            'treeal.charge_expiration_seconds' => 3600,
            'treeal.allow_amount_change' => false,
            'treeal.use_managed_locations' => false,
        ]);
    }

    public function test_get_reference_returns_treeal(): void
    {
        $this->assertSame('treeal', $this->service()->getReference());
    }

    public function test_is_active_when_qr_config_complete(): void
    {
        $this->configureActiveTreeal();

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

    public function test_create_charge_puts_cob_and_returns_br_code(): void
    {
        $this->configureActiveTreeal();

        $auth = $this->createMock(TreealAuthService::class);
        $auth->method('authHeaders')->willReturn(['Authorization' => 'Bearer test-token']);

        Http::fake([
            'https://treeal.test/cob/*' => Http::response([
                'txid' => 'abc123txid456789012345678901234',
                'status' => 'ATIVA',
                'revisao' => 0,
                'pixCopiaECola' => '00020126580014br.gov.bcb.pix0136abc',
                'chave' => '00020126580014br.gov.bcb.pix',
            ], 201),
        ]);

        $result = $this->service($auth)->createCharge(25.50, [
            'name' => 'João Silva',
            'document' => '12345678909',
        ], 'abc123txid456789012345678901234', 'Pagamento teste');

        $this->assertTrue($result['success']);
        $this->assertSame('00020126580014br.gov.bcb.pix0136abc', $result['brCode']);
        $this->assertSame('abc123txid456789012345678901234', $result['correlationID']);
        $this->assertSame('WAITING_FOR_APPROVAL', $result['status']);
    }

    public function test_get_charge_status_maps_concluida_to_paid_out(): void
    {
        $this->configureActiveTreeal();

        $auth = $this->createMock(TreealAuthService::class);
        $auth->method('authHeaders')->willReturn(['Authorization' => 'Bearer test-token']);

        Http::fake([
            'https://treeal.test/cob/*' => Http::response([
                'txid' => 'abc123txid456789012345678901234',
                'status' => 'CONCLUIDA',
                'pix' => [
                    [
                        'endToEndId' => 'E12345678202009091221kkkkkkkkkkk',
                        'horario' => '2020-09-09T20:15:00.358Z',
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service($auth)->getChargeStatus('abc123txid456789012345678901234');

        $this->assertTrue($result['success']);
        $this->assertSame('PAID_OUT', $result['status']);
        $this->assertSame('E12345678202009091221kkkkkkkkkkk', $result['raw']['endToEndId']);
    }

    public function test_map_charge_status_handles_treeal_and_bacen_variants(): void
    {
        $service = $this->service();

        $this->assertSame('PAID_OUT', $service->mapChargeStatus('CONCLUIDA'));
        $this->assertSame('PAID_OUT', $service->mapChargeStatus('LIQUIDATED'));
        $this->assertSame('WAITING_FOR_APPROVAL', $service->mapChargeStatus('ATIVA'));
        $this->assertSame('CANCELED', $service->mapChargeStatus('REMOVIDA_PELO_PSP'));
        $this->assertSame('CANCELED', $service->mapChargeStatus('REMOVIDO_PELO_PSP'));
    }

    public function test_create_payout_returns_not_configured_without_contas_credentials(): void
    {
        $contasPixOut = $this->createMock(TreealContasPixOutService::class);
        $contasPixOut->method('isConfigured')->willReturn(false);

        $result = $this->service(null, $contasPixOut)->createPayout(10.0, 'key@test.com', 'email');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('TREEAL_CONTAS', $result['message']);
    }

    public function test_create_payout_initiates_dict_payment(): void
    {
        $contasPixOut = $this->createMock(TreealContasPixOutService::class);
        $contasPixOut->method('isConfigured')->willReturn(true);
        $contasPixOut->method('formatPixKeyForDict')->willReturn('key@test.com');
        $contasPixOut->method('buildDictPaymentBody')->willReturn([
            'pixKey' => 'key@test.com',
            'payment' => ['currency' => 'BRL', 'amount' => 10.0],
        ]);
        $contasPixOut->method('initiatePaymentByDict')->willReturn([
            'success' => true,
            'status' => 202,
            'data' => [
                'endToEndId' => 'E4397869720260519000408508c56c02',
                'status' => 'PROCESSING',
                'id' => 12345,
            ],
        ]);

        $result = $this->service(null, $contasPixOut)->createPayout(
            10.0,
            'key@test.com',
            'email',
            'Saque teste',
            'corr123'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('E4397869720260519000408508c56c02', $result['referenceCode']);
        $this->assertSame('PROCESSING', $result['status']);
    }

    public function test_get_payout_status_queries_by_end_to_end_id(): void
    {
        $contasPixOut = $this->createMock(TreealContasPixOutService::class);
        $contasPixOut->method('isConfigured')->willReturn(true);
        $contasPixOut->method('getPaymentByEndToEndId')->willReturn([
            'success' => true,
            'data' => [
                'data' => [
                    'endToEndId' => 'E4397869720260519000408508c56c02',
                    'status' => 'LIQUIDATED',
                ],
            ],
        ]);

        $result = $this->service(null, $contasPixOut)->getPayoutStatus('E4397869720260519000408508c56c02');

        $this->assertTrue($result['success']);
        $this->assertSame('COMPLETED', $result['status']);
    }

    public function test_create_refund_puts_devolucao(): void
    {
        $this->configureActiveTreeal();

        $auth = $this->createMock(TreealAuthService::class);
        $auth->method('authHeaders')->willReturn(['Authorization' => 'Bearer test-token']);

        Http::fake([
            'https://treeal.test/pix/*/devolucao/*' => Http::response([
                'id' => 'abc123',
                'rtrId' => 'D12345678202009091000abcde123456',
                'valor' => '25.50',
                'status' => 'EM_PROCESSAMENTO',
            ], 201),
        ]);

        $result = $this->service($auth)->createRefund(
            'E12345678202009091221abcdef12345',
            25.50,
            'Estorno solicitado'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('abc123', $result['refundId']);
        $this->assertSame('EM_PROCESSAMENTO', $result['status']);
    }

    public function test_get_pix_by_end_to_end_id(): void
    {
        $this->configureActiveTreeal();

        $auth = $this->createMock(TreealAuthService::class);
        $auth->method('authHeaders')->willReturn(['Authorization' => 'Bearer test-token']);

        Http::fake([
            'https://treeal.test/pix/E12345678202009091221abcdef12345' => Http::response([
                'endToEndId' => 'E12345678202009091221abcdef12345',
                'txid' => 'cd1fe328c875481285a6f233ae41b662',
                'valor' => '100.00',
                'horario' => '2020-09-10T13:03:33.902Z',
            ], 200),
        ]);

        $result = $this->service($auth)->getPixByEndToEndId('E12345678202009091221abcdef12345');

        $this->assertTrue($result['success']);
        $this->assertSame('cd1fe328c875481285a6f233ae41b662', $result['raw']['txid']);
    }

    public function test_map_payout_status_from_treeal_documentation(): void
    {
        $service = $this->service();

        $this->assertSame('COMPLETED', $service->mapPayoutStatus('LIQUIDATED'));
        $this->assertSame('PROCESSING', $service->mapPayoutStatus('PROCESSING'));
        $this->assertSame('CANCELLED', $service->mapPayoutStatus('CANCELED'));
        $this->assertSame('REFUNDED', $service->mapPayoutStatus('PARTIALLY REFUNDED'));
    }

    public function test_pix_acquirer_manager_resolves_treeal(): void
    {
        $resolved = app(PixAcquirerManager::class)->resolve('treeal');

        $this->assertInstanceOf(TreealPixAcquirerService::class, $resolved);
        $this->assertSame('treeal', $resolved->getReference());
    }
}
