<?php

namespace Tests\Unit\Services\Treeal;

use App\Services\TreealContas\TreealContasApiClient;
use App\Services\TreealContas\TreealContasAuthService;
use App\Services\TreealContas\TreealContasWebhookRegistrationService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TreealContasWebhookRegistrationServiceTest extends TestCase
{
    private string $certPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->certPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'treeal-contas-wh-reg.pfx';
        file_put_contents($this->certPath, 'pfx');

        config([
            'app.url' => 'https://gateway.test',
            'treeal_contas.base_url' => 'https://treeal-contas.test',
            'treeal_contas.client_id' => 'client-id',
            'treeal_contas.client_secret' => 'client-secret',
            'treeal_contas.cert_format' => 'pfx',
            'treeal_contas.cert_pfx_path' => $this->certPath,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->certPath)) {
            @unlink($this->certPath);
        }

        parent::tearDown();
    }

    public function test_resolve_webhook_uri_defaults_to_app_url(): void
    {
        $service = new TreealContasWebhookRegistrationService(
            $this->createMock(TreealContasApiClient::class),
            $this->createMock(TreealContasAuthService::class),
        );

        $this->assertSame('https://gateway.test/treeal/contas/webhook', $service->resolveWebhookUri());
    }

    public function test_register_transfer_webhook_posts_to_api(): void
    {
        Http::fake([
            'https://treeal-contas.test/webhooks/transfer' => Http::response([
                'id' => '497f6eca-6276-4993-bfeb-53cbbbba6f08',
                'type' => 'TRANSFER',
                'uri' => 'https://gateway.test/treeal/contas/webhook',
                'enabled' => true,
            ], 201),
        ]);

        $auth = $this->createMock(TreealContasAuthService::class);
        $auth->method('isConfigured')->willReturn(true);
        $auth->method('authHeaders')->willReturn(['Authorization' => 'Bearer token']);

        $client = new TreealContasApiClient($auth);
        $result = (new TreealContasWebhookRegistrationService($client, $auth))->registerWebhook('TRANSFER');

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://treeal-contas.test/webhooks/transfer'
                && ($request->data()['uri'] ?? null) === 'https://gateway.test/treeal/contas/webhook'
                && ($request->data()['method'] ?? null) === 'POST';
        });
    }
}
