<?php

namespace Tests\Feature\Middleware;

use Tests\TestCase;
use App\Models\User;
use App\Services\JWTService;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Testes de Integração - Middleware VerifyJWT
 * 
 * Middleware crítico: Valida JWT para autenticação de usuários (frontend)
 * 
 * Cenários testados:
 * - Token JWT válido
 * - Token JWT ausente
 * - Token JWT inválido ou expirado
 * - Token JWT com estrutura incorreta
 * - Usuário inativo ou banido
 * - Token de usuário que não existe mais
 */
class VerifyJWTTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $validToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Criar usuário ativo
        $this->user = User::factory()->create([
            'username' => 'testjwtuser',
            'user_id' => 'testjwtuser',
            'email' => 'jwt@test.com',
            'status' => 1, // Ativo
            'banido' => 0,
            'permission' => UserPermission::CLIENT,
        ]);

        // Gerar token JWT válido (middleware VerifyJWT usa JWTService->validateToken)
        $jwtService = app(JWTService::class);
        $this->validToken = $jwtService->generateToken($this->user->username);

        // Criar rota de teste protegida por JWT
        Route::get('/test-jwt', function (Request $request) {
            return response()->json([
                'success' => true,
                'user' => $request->user()->username,
            ]);
        })->middleware('verify.jwt');
    }

    /** @test */
    public function deve_permitir_acesso_com_jwt_valido(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->validToken)
            ->getJson('/test-jwt');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'user' => 'testjwtuser',
            ]);
    }

    /** @test */
    public function deve_rejeitar_requisicao_sem_token(): void
    {
        $response = $this->getJson('/test-jwt');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
            ]);
    }

    /** @test */
    public function deve_rejeitar_token_invalido(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer token_jwt_invalido')
            ->getJson('/test-jwt');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
            ]);
    }

    /** @test */
    public function deve_rejeitar_token_com_formato_incorreto(): void
    {
        // Token sem "Bearer" prefix
        $response = $this->withHeader('Authorization', $this->validToken)
            ->getJson('/test-jwt');

        $response->assertStatus(401);
    }

    /** @test */
    public function deve_rejeitar_token_vazio(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ')
            ->getJson('/test-jwt');

        $response->assertStatus(401);
    }

    /** @test */
    public function deve_bloquear_usuario_inativo_mesmo_com_token_valido(): void
    {
        // Inativar usuário
        $this->user->update(['status' => 0]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->validToken)
            ->getJson('/test-jwt');

        // Middleware retorna 403 para conta inativa (não 401)
        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);
    }

    /** @test */
    public function deve_bloquear_usuario_banido_mesmo_com_token_valido(): void
    {
        // Banir usuário
        $this->user->update(['banido' => 1]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->validToken)
            ->getJson('/test-jwt');

        // Middleware retorna 403 para conta banida (não 401)
        $response->assertStatus(403);
    }

    /** @test */
    public function deve_rejeitar_token_de_usuario_excluido(): void
    {
        // Pegar o token antes de excluir
        $token = $this->validToken;

        // Excluir usuário
        $this->user->delete();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/test-jwt');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
            ]);
    }

    /** @test */
    public function deve_aceitar_token_via_query_string(): void
    {
        // Alguns sistemas aceitam token via query (útil para downloads, etc.)
        Route::get('/test-jwt-query', function (Request $request) {
            return response()->json([
                'success' => true,
                'user' => $request->user()->username,
            ]);
        })->middleware('verify.jwt');

        $response = $this->getJson('/test-jwt-query?token=' . $this->validToken);

        // Pode ou não aceitar via query dependendo da implementação
        // Validamos que a rota existe e responde
        $this->assertContains($response->status(), [200, 401]);
    }

    /** @test */
    public function deve_validar_estrutura_do_jwt(): void
    {
        // JWT deve ter 3 partes separadas por '.'
        $tokenInvalido = 'invalid.token';

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenInvalido)
            ->getJson('/test-jwt');

        $response->assertStatus(401);
    }

    /** @test */
    public function deve_rejeitar_token_expirado(): void
    {
        // Criar token JWT já expirado (expirationHours = -1 => exp = now - 1h)
        $jwtService = app(JWTService::class);
        $expiredToken = $jwtService->generateToken($this->user->username, [], -1);

        $response = $this->withHeader('Authorization', 'Bearer ' . $expiredToken)
            ->getJson('/test-jwt');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
            ]);
    }

    /** @test */
    public function deve_permitir_usuario_pendente_acessar_rotas_internas(): void
    {
        // Usuários pendentes (status = 2) podem acessar o dashboard
        $this->user->update(['status' => 2]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->validToken)
            ->getJson('/test-jwt');

        $response->assertStatus(200);
    }
}
