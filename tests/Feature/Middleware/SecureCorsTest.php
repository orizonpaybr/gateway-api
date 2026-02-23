<?php

namespace Tests\Feature\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use App\Http\Middleware\SecureCors;

/**
 * Testes do middleware SecureCors
 *
 * Cobre: origens permitidas, preflight OPTIONS, produção vs desenvolvimento,
 * resposta sem Origin, headers na resposta.
 */
class SecureCorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
    }

    /** @test */
    public function preflight_options_retorna_200_com_headers_cors_em_ambiente_nao_producao(): void
    {
        $this->assertNotEquals('production', app()->environment());

        $request = Request::create('/api/test', 'OPTIONS');
        $request->headers->set('Origin', 'http://localhost:3000');

        $middleware = new SecureCors();
        $response = $middleware->handle($request, fn ($r) => response()->json(['ok' => true]));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('http://localhost:3000', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertEquals('true', $response->headers->get('Access-Control-Allow-Credentials'));
        $this->assertStringContainsString('GET', $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('Authorization', $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertEquals('86400', $response->headers->get('Access-Control-Max-Age'));
    }

    /** @test */
    public function preflight_options_com_origem_localhost_5173_permite_em_dev(): void
    {
        $request = Request::create('/api/test', 'OPTIONS');
        $request->headers->set('Origin', 'http://localhost:5173');

        $middleware = new SecureCors();
        $response = $middleware->handle($request, fn ($r) => response()->json([]));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('http://localhost:5173', $response->headers->get('Access-Control-Allow-Origin'));
    }

    /** @test */
    public function requisicao_get_com_origem_permitida_recebe_headers_cors_na_resposta(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Origin', 'http://127.0.0.1:3000');

        $middleware = new SecureCors();
        $response = $middleware->handle($request, fn ($r) => response()->json(['data' => 'ok']));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('http://127.0.0.1:3000', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertEquals('true', $response->headers->get('Access-Control-Allow-Credentials'));
    }

    /** @test */
    public function preflight_em_producao_com_origem_nao_permitida_retorna_403(): void
    {
        $this->app['env'] = 'production';

        $request = Request::create('/api/test', 'OPTIONS');
        $request->headers->set('Origin', 'https://evil.com');

        $middleware = new SecureCors();
        $response = $middleware->handle($request, fn ($r) => response()->json([]));

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertStringContainsString('Origin not allowed', $response->getContent());

        $this->app['env'] = 'testing';
    }

    /** @test */
    public function preflight_em_producao_sem_origin_nao_retorna_access_control_allow_origin(): void
    {
        $this->app['env'] = 'production';

        $request = Request::create('/api/test', 'OPTIONS');
        // Sem header Origin (ex: curl, Postman) – em produção pode retornar 403 ou 200 sem header
        $request->headers->remove('Origin');

        $middleware = new SecureCors();
        $response = $middleware->handle($request, fn ($r) => response()->json([]));

        $this->assertEmpty($response->headers->get('Access-Control-Allow-Origin'));
        if ($response->getStatusCode() === 200) {
            $this->assertNotEmpty($response->headers->get('Access-Control-Allow-Methods'));
        }

        $this->app['env'] = 'testing';
    }

    /** @test */
    public function requisicao_normal_em_producao_sem_origin_nao_adiciona_headers_cors(): void
    {
        $this->app['env'] = 'production';

        $request = Request::create('/api/test', 'GET');
        $nextResponse = response()->json(['ok' => true]);

        $middleware = new SecureCors();
        $response = $middleware->handle($request, fn ($r) => $nextResponse);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEmpty($response->headers->get('Access-Control-Allow-Origin'));

        $this->app['env'] = 'testing';
    }

    /** @test */
    public function next_closure_e_chamado_em_requisicao_nao_options(): void
    {
        $request = Request::create('/api/test', 'POST');
        $request->headers->set('Origin', 'http://localhost:3000');
        $nextCalled = false;

        $middleware = new SecureCors();
        $response = $middleware->handle($request, function ($r) use (&$nextCalled) {
            $nextCalled = true;
            return response()->json(['called' => true]);
        });

        $this->assertTrue($nextCalled);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('"called":true', $response->getContent());
    }
}
