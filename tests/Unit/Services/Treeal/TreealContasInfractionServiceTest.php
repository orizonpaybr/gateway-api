<?php

namespace Tests\Unit\Services\Treeal;

use App\Services\TreealContas\TreealContasApiClient;
use App\Services\TreealContas\TreealContasAuthService;
use App\Services\TreealContas\TreealContasInfractionService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TreealContasInfractionServiceTest extends TestCase
{
    private string $certPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->certPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'treeal-contas-infraction.pfx';
        file_put_contents($this->certPath, 'pfx');

        config([
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

    private function service(): TreealContasInfractionService
    {
        $auth = $this->createMock(TreealContasAuthService::class);
        $auth->method('isConfigured')->willReturn(true);
        $auth->method('authHeaders')->willReturn([
            'Authorization' => 'Bearer token',
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);

        return new TreealContasInfractionService(new TreealContasApiClient($auth), $auth);
    }

    public function test_list_infractions_sends_default_date_window(): void
    {
        Http::fake([
            'https://treeal-contas.test/infractions*' => Http::response(['data' => [], 'meta' => []], 200),
        ]);

        $result = $this->service()->listInfractions();

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://treeal-contas.test/infractions')
                && str_contains($request->url(), 'last_change_start')
                && str_contains($request->url(), 'last_change_end');
        });
    }

    public function test_get_infraction_returns_detail(): void
    {
        Http::fake([
            'https://treeal-contas.test/infractions/abc-123' => Http::response([
                'data' => ['id' => 'abc-123', 'endToEndId' => 'E1234', 'status' => 'OPEN'],
            ], 200),
        ]);

        $result = $this->service()->getInfraction('abc-123');

        $this->assertTrue($result['success']);
        $this->assertSame('E1234', $result['raw']['data']['endToEndId']);
    }

    public function test_submit_defense_posts_multipart(): void
    {
        Http::fake([
            'https://treeal-contas.test/infractions/abc-123/defense' => Http::response([
                'data' => ['id' => 'abc-123', 'status' => 'DEFENDED'],
            ], 200),
        ]);

        $result = $this->service()->submitDefense('abc-123', 'Transação legítima, cliente reconhece.');

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://treeal-contas.test/infractions/abc-123/defense'
                && $request->hasHeader('Authorization');
        });
    }

    public function test_submit_defense_requires_text(): void
    {
        $result = $this->service()->submitDefense('abc-123', '   ');

        $this->assertFalse($result['success']);
    }
}
