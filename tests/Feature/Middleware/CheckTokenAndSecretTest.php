<?php

namespace Tests\Feature\Middleware;

use Tests\TestCase;
use App\Models\User;
use App\Models\UsersKey;
use App\Http\Middleware\CheckTokenAndSecret;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Testes de Integração - Middleware CheckTokenAndSecret
 * 
 * Middleware crítico: Valida token+secret para APIs externas
 * 
 * Cenários testados:
 * - Token e secret válidos
 * - Token ou secret ausente
 * - Token ou secret inválido
 * - Usuário inativo ou banido
 * - Token/secret via body, query ou headers
 */
class CheckTokenAndSecretTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private UsersKey $userKey;

    protected function setUp(): void
    {
        parent::setUp();

        // Criar usuário ativo
        $this->user = User::factory()->create([
            'username' => 'testmiddlewareuser',
            'user_id' => 'testmiddlewareuser',
            'status' => 1, // Ativo
            'banido' => 0,
            'permission' => UserPermission::CLIENT,
        ]);

        // Criar credenciais API
        $this->userKey = UsersKey::factory()->create([
            'user_id' => $this->user->user_id,
            'token' => 'valid_middleware_token',
            'secret' => 'valid_middleware_secret',
        ]);

        // Criar rota de teste
        Route::post('/test-middleware', function (Request $request) {
            return response()->json([
                'success' => true,
                'user' => $request->user()->username,
            ]);
        })->middleware('check.token.secret');
    }

    /** @test */
    public function deve_permitir_acesso_com_token_e_secret_validos_via_body(): void
    {
        $response = $this->postJson('/test-middleware', [
            'token' => 'valid_middleware_token',
            'secret' => 'valid_middleware_secret',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'user' => 'testmiddlewareuser',
            ]);
    }

    /** @test */
    public function deve_permitir_acesso_com_token_e_secret_validos_via_headers(): void
    {
        $response = $this->postJson('/test-middleware', [], [
            'api_token' => 'valid_middleware_token',
            'api_secret' => 'valid_middleware_secret',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'user' => 'testmiddlewareuser',
            ]);
    }

    /** @test */
    public function deve_rejeitar_requisicao_sem_token(): void
    {
        $response = $this->postJson('/test-middleware', [
            'secret' => 'valid_middleware_secret',
            // Token ausente
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Token ou Secret ausentes',
            ]);
    }

    /** @test */
    public function deve_rejeitar_requisicao_sem_secret(): void
    {
        $response = $this->postJson('/test-middleware', [
            'token' => 'valid_middleware_token',
            // Secret ausente
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'Token ou Secret ausentes',
            ]);
    }

    /** @test */
    public function deve_rejeitar_token_invalido(): void
    {
        $response = $this->postJson('/test-middleware', [
            'token' => 'token_invalido',
            'secret' => 'valid_middleware_secret',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Token ou Secret inválidos',
            ]);
    }

    /** @test */
    public function deve_rejeitar_secret_invalido(): void
    {
        $response = $this->postJson('/test-middleware', [
            'token' => 'valid_middleware_token',
            'secret' => 'secret_invalido',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Token ou Secret inválidos',
            ]);
    }

    /** @test */
    public function deve_bloquear_usuario_inativo(): void
    {
        $this->user->update(['status' => 0]); // Inativo

        $response = $this->postJson('/test-middleware', [
            'token' => 'valid_middleware_token',
            'secret' => 'valid_middleware_secret',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Conta inativa ou bloqueada. Entre em contato com o suporte.',
            ]);
    }

    /** @test */
    public function deve_bloquear_usuario_banido(): void
    {
        $this->user->update(['banido' => 1]); // Banido

        $response = $this->postJson('/test-middleware', [
            'token' => 'valid_middleware_token',
            'secret' => 'valid_middleware_secret',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Conta inativa ou bloqueada. Entre em contato com o suporte.',
            ]);
    }

    /** @test */
    public function deve_permitir_usuario_pendente_acessar_apis_externas(): void
    {
        $this->user->update(['status' => 2]); // Pendente

        $response = $this->postJson('/test-middleware', [
            'token' => 'valid_middleware_token',
            'secret' => 'valid_middleware_secret',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    /** @test */
    public function deve_injetar_usuario_no_request(): void
    {
        Route::post('/test-user-injection', function (Request $request) {
            $user = $request->user();
            $userAuth = $request->input('user_auth');

            return response()->json([
                'user_from_resolver' => $user ? $user->username : null,
                'user_from_merge' => $userAuth ? $userAuth->username : null,
            ]);
        })->middleware('check.token.secret');

        $response = $this->postJson('/test-user-injection', [
            'token' => 'valid_middleware_token',
            'secret' => 'valid_middleware_secret',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'user_from_resolver' => 'testmiddlewareuser',
                'user_from_merge' => 'testmiddlewareuser',
            ]);
    }
}
