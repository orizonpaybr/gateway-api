<?php

namespace Tests\Unit\Services\Treeal;

use App\Services\Treeal\TreealAuthService;
use App\Services\Treeal\TreealWebhookRegistrationService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TreealWebhookRegistrationServiceTest extends TestCase
{
    private string $certPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->certPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'treeal-webhook-reg-test.pfx';
        file_put_contents($this->certPath, 'pfx');

        config([
            'app.url' => 'https://gateway.test',
            'treeal.client_id' => 'client-id',
            'treeal.client_secret' => 'client-secret',
            'treeal.base_url' => 'https://treeal.test',
            'treeal.pix_key' => '00020126580014br.gov.bcb.pix',
            'treeal.cert_format' => 'pfx',
            'treeal.cert_pfx_path' => $this->certPath,
            'treeal.timeout' => 5,
            'treeal.webhook_base_url' => '',
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->certPath)) {
            @unlink($this->certPath);
        }

        parent::tearDown();
    }

    public function test_resolve_webhook_base_url_defaults_to_app_url(): void
    {
        $service = new TreealWebhookRegistrationService($this->createMock(TreealAuthService::class));

        $this->assertSame('https://gateway.test/treeal/webhook', $service->resolveWebhookBaseUrl());
    }

    public function test_configure_pix_webhook_sends_put_with_webhook_url(): void
    {
        Http::fake([
            'https://treeal.test/webhook/*' => Http::response([
                'webhookUrl' => 'https://gateway.test/treeal/webhook',
                'chave' => '00020126580014br.gov.bcb.pix',
                'criacao' => '2020-09-09T20:15:00.358Z',
            ], 200),
        ]);

        $auth = $this->createMock(TreealAuthService::class);
        $auth->method('authHeaders')->willReturn(['Authorization' => 'Bearer token']);

        $result = (new TreealWebhookRegistrationService($auth))->configurePixWebhook();

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), 'https://treeal.test/webhook/')
                && ($request->data()['webhookUrl'] ?? null) === 'https://gateway.test/treeal/webhook';
        });
    }

    public function test_delete_pix_webhook_returns_success_on_204(): void
    {
        Http::fake([
            'https://treeal.test/webhook/*' => Http::response(null, 204),
        ]);

        $auth = $this->createMock(TreealAuthService::class);
        $auth->method('authHeaders')->willReturn(['Authorization' => 'Bearer token']);

        $result = (new TreealWebhookRegistrationService($auth))->deletePixWebhook();

        $this->assertTrue($result['success']);
    }
}
