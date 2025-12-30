<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\Nivel;
use App\Models\App;
use App\Models\UsersKey;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Integração - API de Níveis de Gamificação
 * 
 * Cobre:
 * - Endpoint GET /api/admin/levels
 * - Endpoint GET /api/admin/levels/{id}
 * - Endpoint PUT /api/admin/levels/{id}
 * - Endpoint POST /api/admin/levels/toggle-active
 * - Autenticação
 * - Validação de requests
 * - Respostas JSON
 * - Tratamento de erros
 * - Ordenação
 */
class LevelsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Criar usuário admin e obter token
        $this->user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'status' => 1,
            'banido' => 0,
            'permission' => UserPermission::ADMIN,
        ]);

        // Criar UsersKey (necessário para login)
        UsersKey::factory()->create([
            'user_id' => $this->user->user_id ?? $this->user->username,
            'token' => 'test_token_' . $this->user->username,
        ]);

        // Fazer login e obter token
        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => 'testuser',
            'password' => 'password123',
        ]);

        $this->token = $loginResponse->json('token') ?? $loginResponse->json('data.token');
    }

    /**
     * Teste: Deve listar níveis com sucesso
     */
    public function test_should_list_levels(): void
    {
        Nivel::create([
            'nome' => 'Bronze',
            'cor' => '#CD7F32',
            'minimo' => 0,
            'maximo' => 1000,
        ]);
        Nivel::create([
            'nome' => 'Prata',
            'cor' => '#C0C0C0',
            'minimo' => 1000,
            'maximo' => 5000,
        ]);

        App::create(['niveis_ativo' => true]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/levels');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'niveis',
                    'niveis_ativo',
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'niveis_ativo' => true,
                ],
            ]);

        $this->assertCount(2, $response->json('data.niveis'));
    }

    /**
     * Teste: Deve listar níveis ordenados por mínimo
     */
    public function test_should_list_levels_ordered_by_minimo(): void
    {
        // Criar níveis em ordem diferente
        Nivel::create([
            'nome' => 'Prata',
            'cor' => '#C0C0C0',
            'minimo' => 1000,
            'maximo' => 5000,
        ]);
        Nivel::create([
            'nome' => 'Bronze',
            'cor' => '#CD7F32',
            'minimo' => 0,
            'maximo' => 1000,
        ]);

        App::create(['niveis_ativo' => false]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/levels');

        $response->assertStatus(200);
        $niveis = $response->json('data.niveis');
        
        // Deve estar ordenado por mínimo (asc)
        $this->assertEquals('Bronze', $niveis[0]['nome']);
        $this->assertEquals('Prata', $niveis[1]['nome']);
    }

    /**
     * Teste: Deve obter nível específico
     */
    public function test_should_get_specific_level(): void
    {
        $nivel = Nivel::create([
            'nome' => 'Bronze',
            'cor' => '#CD7F32',
            'minimo' => 0,
            'maximo' => 1000,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/levels/' . $nivel->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'nome',
                    'cor',
                    'minimo',
                    'maximo',
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $nivel->id,
                    'nome' => 'Bronze',
                ],
            ]);
    }

    /**
     * Teste: Deve retornar 404 para nível inexistente
     */
    public function test_should_return_404_for_nonexistent_level(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/levels/999');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Nível não encontrado.',
            ]);
    }

    /**
     * Teste: Deve atualizar nível com sucesso
     */
    public function test_should_update_level(): void
    {
        $nivel = Nivel::create([
            'nome' => 'Bronze',
            'cor' => '#CD7F32',
            'minimo' => 0,
            'maximo' => 1000,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson('/api/admin/levels/' . $nivel->id, [
            'nome' => 'Bronze Atualizado',
            'cor' => '#FF0000',
            'minimo' => 0,
            'maximo' => 2000,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ])
            ->assertJson([
                'success' => true,
                'message' => 'Nível atualizado com sucesso!',
            ]);

        $nivel->refresh();
        $this->assertEquals('Bronze Atualizado', $nivel->nome);
        $this->assertEquals('#FF0000', $nivel->cor);
        $this->assertEquals(2000, $nivel->maximo);
    }

    /**
     * Teste: Deve retornar 404 ao atualizar nível inexistente
     */
    public function test_should_return_404_when_updating_nonexistent_level(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson('/api/admin/levels/999', [
            'nome' => 'Teste',
            'minimo' => 0,
            'maximo' => 1000,
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Nível não encontrado.',
            ]);
    }

    /**
     * Teste: Deve validar campos ao atualizar nível
     */
    public function test_should_validate_fields_when_updating_level(): void
    {
        $nivel = Nivel::create([
            'nome' => 'Bronze',
            'cor' => '#CD7F32',
            'minimo' => 0,
            'maximo' => 1000,
        ]);

        // Tentar atualizar com máximo menor que mínimo
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson('/api/admin/levels/' . $nivel->id, [
            'nome' => 'Teste',
            'minimo' => 1000,
            'maximo' => 500, // Menor que mínimo
        ]);

        $response->assertStatus(422);
    }

    /**
     * Teste: Deve ativar sistema de níveis
     */
    public function test_should_activate_levels_system(): void
    {
        App::create(['niveis_ativo' => false]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/levels/toggle-active', [
            'niveis_ativo' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'niveis_ativo',
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'niveis_ativo' => true,
                ],
            ]);

        $settings = App::first();
        $this->assertTrue($settings->niveis_ativo);
    }

    /**
     * Teste: Deve desativar sistema de níveis
     */
    public function test_should_deactivate_levels_system(): void
    {
        App::create(['niveis_ativo' => true]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/levels/toggle-active', [
            'niveis_ativo' => false,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'niveis_ativo' => false,
                ],
            ]);

        $settings = App::first();
        $this->assertFalse($settings->niveis_ativo);
    }

    /**
     * Teste: Deve validar toggle active request
     */
    public function test_should_validate_toggle_active_request(): void
    {
        App::create(['niveis_ativo' => false]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/levels/toggle-active', [
            // Sem niveis_ativo
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'errors',
            ]);
    }

    /**
     * Teste: Deve retornar 404 quando App não existe para toggle
     */
    public function test_should_return_404_when_app_not_found_for_toggle(): void
    {
        // Não criar App

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/levels/toggle-active', [
            'niveis_ativo' => true,
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Configurações do sistema não encontradas.',
            ]);
    }

    /**
     * Teste: Deve retornar erro 401 sem autenticação
     */
    public function test_should_require_authentication(): void
    {
        $response = $this->getJson('/api/admin/levels');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve retornar erro 403 para não-admin
     */
    public function test_should_require_admin_permission(): void
    {
        $nonAdmin = AuthTestHelper::createTestUser([
            'username' => 'nonadmin_' . uniqid(),
            'email' => 'nonadmin_' . uniqid() . '@example.com',
            'permission' => UserPermission::CLIENT,
        ]);

        UsersKey::factory()->create([
            'user_id' => $nonAdmin->user_id ?? $nonAdmin->username,
            'token' => 'test_token_' . $nonAdmin->username,
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => $nonAdmin->username,
            'password' => 'password123',
        ]);

        $nonAdminToken = $loginResponse->json('token') ?? $loginResponse->json('data.token');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $nonAdminToken,
        ])->getJson('/api/admin/levels');

        // Deve retornar 403 ou 401 dependendo do middleware
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve retornar lista vazia quando não há níveis
     */
    public function test_should_return_empty_list_when_no_levels(): void
    {
        App::create(['niveis_ativo' => false]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/levels');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.niveis'));
    }
}








