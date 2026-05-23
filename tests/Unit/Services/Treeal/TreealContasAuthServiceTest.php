<?php

namespace Tests\Unit\Services\Treeal;

use App\Services\TreealContas\TreealContasAuthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TreealContasAuthServiceTest extends TestCase
{
    private string $certPath;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->certPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'treeal-contas-auth-test.pfx';
        file_put_contents($this->certPath, 'dummy-pfx');

        config([
            'treeal_contas.base_url' => 'https://treeal-contas-auth.test',
            'treeal_contas.client_id' => '11111111-1111-1111-1111-111111111111',
            'treeal_contas.client_secret' => 'client-secret',
            'treeal_contas.scope' => 'pix.read pix.write',
            'treeal_contas.timeout' => 5,
            'treeal_contas.token_cache_buffer_seconds' => 30,
            'treeal_contas.cert_format' => 'pfx',
            'treeal_contas.cert_pfx_path' => $this->certPath,
            'treeal_contas.verify_ssl' => false,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->certPath)) {
            @unlink($this->certPath);
        }

        parent::tearDown();
    }

    public function test_get_access_token_requests_json_body_with_onz_fields(): void
    {
        Http::fake([
            'https://treeal-contas-auth.test/oauth/token' => Http::response([
                'accessToken' => 'onz-token',
                'tokenType' => 'Bearer',
                'expiresAt' => time() + 3600,
                'scope' => 'pix.read pix.write',
            ], 201),
        ]);

        $token = (new TreealContasAuthService)->getAccessToken();

        $this->assertSame('onz-token', $token);
        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://treeal-contas-auth.test/oauth/token'
                && ($body['clientId'] ?? null) === '11111111-1111-1111-1111-111111111111'
                && ($body['clientSecret'] ?? null) === 'client-secret'
                && ($body['grantType'] ?? null) === 'client_credentials'
                && ($body['scope'] ?? null) === 'pix.read pix.write';
        });
    }

    public function test_get_access_token_accepts_snake_case_fallback(): void
    {
        Http::fake([
            'https://treeal-contas-auth.test/oauth/token' => Http::response([
                'access_token' => 'legacy-token',
                'expires_in' => 300,
            ], 200),
        ]);

        $this->assertSame('legacy-token', (new TreealContasAuthService)->getAccessToken());
    }

    public function test_is_configured_requires_credentials_and_certificate(): void
    {
        $this->assertTrue((new TreealContasAuthService)->isConfigured());

        config(['treeal_contas.cert_pfx_path' => '']);
        $this->assertFalse((new TreealContasAuthService)->isConfigured());
    }
}
