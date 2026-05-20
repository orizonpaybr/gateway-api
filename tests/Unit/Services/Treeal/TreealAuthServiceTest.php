<?php

namespace Tests\Unit\Services\Treeal;

use App\Services\Treeal\TreealAuthService;
use App\Services\Treeal\TreealMtlsOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TreealAuthServiceTest extends TestCase
{
    private string $certPath;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->certPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'treeal-auth-test.pfx';
        file_put_contents($this->certPath, 'dummy-pfx');

        config([
            'treeal.base_url' => 'https://treeal-auth.test',
            'treeal.client_id' => 'client-id',
            'treeal.client_secret' => 'client-secret',
            'treeal.scope' => '',
            'treeal.timeout' => 5,
            'treeal.token_cache_buffer_seconds' => 30,
            'treeal.cert_format' => 'pfx',
            'treeal.cert_pfx_path' => $this->certPath,
            'treeal.cert_pfx_password' => 'secret',
            'treeal.verify_ssl' => false,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->certPath)) {
            @unlink($this->certPath);
        }

        parent::tearDown();
    }

    public function test_get_token_returns_cached_value_without_http_call(): void
    {
        Cache::put('treeal_access_token', 'cached-token', now()->addMinutes(5));

        Http::fake();

        $token = (new TreealAuthService)->getToken();

        $this->assertSame('cached-token', $token);
        Http::assertNothingSent();
    }

    public function test_get_token_requests_new_token_and_caches_with_buffer(): void
    {
        Http::fake([
            'https://treeal-auth.test/oauth/token' => Http::response([
                'access_token' => 'fresh-token',
                'expires_in' => 300,
                'token_type' => 'Bearer',
            ], 200),
        ]);

        $service = new TreealAuthService;
        $token = $service->getToken();

        $this->assertSame('fresh-token', $token);
        $this->assertSame('fresh-token', Cache::get('treeal_access_token'));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://treeal-auth.test/oauth/token'
                && $request['grant_type'] === 'client_credentials'
                && $request['client_id'] === 'client-id'
                && $request['client_secret'] === 'client-secret';
        });
    }

    public function test_invalidate_token_forces_new_request(): void
    {
        Http::fake([
            'https://treeal-auth.test/oauth/token' => Http::sequence()
                ->push(['access_token' => 'token-a', 'expires_in' => 300])
                ->push(['access_token' => 'token-b', 'expires_in' => 300]),
        ]);

        $service = new TreealAuthService;

        $this->assertSame('token-a', $service->getToken());
        $service->invalidateToken();
        $this->assertSame('token-b', $service->getToken());

        Http::assertSentCount(2);
    }

    public function test_missing_credentials_throw_runtime_exception(): void
    {
        config(['treeal.client_id' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Credenciais TREEAL');

        (new TreealAuthService)->getToken();
    }

    public function test_missing_mtls_certificate_throw_runtime_exception(): void
    {
        config(['treeal.cert_pfx_path' => '']);

        Http::fake();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Certificado mTLS TREEAL QR');

        (new TreealAuthService)->getToken();
    }
}
