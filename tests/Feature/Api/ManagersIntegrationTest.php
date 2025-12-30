<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\UsersKey;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Integração - API de Gerentes
 * 
 * Cobre:
 * - Endpoint de listar gerentes
 * - Autenticação
 * - Validação de requests
 * - Respostas JSON
 * - Tratamento de erros
 * - Filtros e busca
 * - Paginação
 */
class ManagersIntegrationTest extends TestCase
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
     * Teste: Deve listar gerentes com sucesso
     */
    public function test_should_list_managers(): void
    {
        // Criar gerentes
        AuthTestHelper::createTestUser([
            'username' => 'manager1_' . uniqid(),
            'email' => 'manager1_' . uniqid() . '@example.com',
            'permission' => UserPermission::MANAGER,
            'name' => 'Gerente 1',
        ]);

        AuthTestHelper::createTestUser([
            'username' => 'manager2_' . uniqid(),
            'email' => 'manager2_' . uniqid() . '@example.com',
            'permission' => UserPermission::MANAGER,
            'name' => 'Gerente 2',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/users-managers');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'managers' => [
                        '*' => [
                            'id',
                            'name',
                            'username',
                            'email',
                            'permission',
                            'status',
                        ],
                    ],
                    'pagination' => [
                        'current_page',
                        'per_page',
                        'total',
                        'last_page',
                    ],
                ],
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertGreaterThanOrEqual(2, count($response->json('data.managers')));
    }

    /**
     * Teste: Deve filtrar gerentes por busca
     */
    public function test_should_filter_managers_by_search(): void
    {
        // Criar gerentes
        AuthTestHelper::createTestUser([
            'username' => 'manager_search_' . uniqid(),
            'email' => 'manager_search_' . uniqid() . '@example.com',
            'permission' => UserPermission::MANAGER,
            'name' => 'Gerente Busca',
        ]);

        AuthTestHelper::createTestUser([
            'username' => 'manager_other_' . uniqid(),
            'email' => 'manager_other_' . uniqid() . '@example.com',
            'permission' => UserPermission::MANAGER,
            'name' => 'Outro Gerente',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/users-managers?search=Busca');

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        
        $managers = $response->json('data.managers');
        $found = false;
        foreach ($managers as $manager) {
            if (
                stripos($manager['name'] ?? '', 'Busca') !== false ||
                stripos($manager['email'] ?? '', 'Busca') !== false ||
                stripos($manager['username'] ?? '', 'Busca') !== false
            ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found || count($managers) === 0);
    }

    /**
     * Teste: Deve paginar gerentes
     */
    public function test_should_paginate_managers(): void
    {
        // Criar múltiplos gerentes
        for ($i = 0; $i < 15; $i++) {
            AuthTestHelper::createTestUser([
                'username' => 'manager_pag_' . $i . '_' . uniqid(),
                'email' => 'manager_pag_' . $i . '_' . uniqid() . '@example.com',
                'permission' => UserPermission::MANAGER,
                'name' => "Gerente {$i}",
            ]);
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/users-managers?per_page=10&page=1');

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        $this->assertLessThanOrEqual(10, count($response->json('data.managers')));
        $this->assertGreaterThanOrEqual(1, $response->json('data.pagination.last_page'));
    }

    /**
     * Teste: Deve retornar apenas gerentes (permission = MANAGER)
     */
    public function test_should_only_return_managers(): void
    {
        // Criar gerente
        AuthTestHelper::createTestUser([
            'username' => 'manager_only_' . uniqid(),
            'email' => 'manager_only_' . uniqid() . '@example.com',
            'permission' => UserPermission::MANAGER,
            'name' => 'Gerente Apenas',
        ]);

        // Criar cliente (não deve aparecer)
        AuthTestHelper::createTestUser([
            'username' => 'client_' . uniqid(),
            'email' => 'client_' . uniqid() . '@example.com',
            'permission' => UserPermission::CLIENT,
            'name' => 'Cliente',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/users-managers');

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        
        $managers = $response->json('data.managers');
        foreach ($managers as $manager) {
            $this->assertEquals(UserPermission::MANAGER, $manager['permission']);
        }
    }

    /**
     * Teste: Deve retornar erro 401 sem autenticação
     */
    public function test_should_require_authentication(): void
    {
        $response = $this->getJson('/api/admin/users-managers');

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
        ])->getJson('/api/admin/users-managers');

        // Deve retornar 403 ou 401 dependendo do middleware
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve validar per_page máximo
     */
    public function test_should_validate_per_page_maximum(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/users-managers?per_page=200');

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        // Deve limitar a 100
        $this->assertLessThanOrEqual(100, $response->json('data.pagination.per_page'));
    }

    /**
     * Teste: Deve validar page mínimo
     */
    public function test_should_validate_page_minimum(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/users-managers?page=0');

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        // Deve usar página 1
        $this->assertEquals(1, $response->json('data.pagination.current_page'));
    }

    /**
     * Teste: Deve ordenar gerentes por nome
     */
    public function test_should_order_managers_by_name(): void
    {
        // Criar gerentes com nomes diferentes
        AuthTestHelper::createTestUser([
            'username' => 'manager_z_' . uniqid(),
            'email' => 'manager_z_' . uniqid() . '@example.com',
            'permission' => UserPermission::MANAGER,
            'name' => 'Z Gerente',
        ]);

        AuthTestHelper::createTestUser([
            'username' => 'manager_a_' . uniqid(),
            'email' => 'manager_a_' . uniqid() . '@example.com',
            'permission' => UserPermission::MANAGER,
            'name' => 'A Gerente',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/users-managers');

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        
        $managers = $response->json('data.managers');
        if (count($managers) >= 2) {
            $first = $managers[0]['name'] ?? '';
            $second = $managers[1]['name'] ?? '';
            $this->assertLessThanOrEqual(0, strcasecmp($first, $second));
        }
    }

    /**
     * Teste: Deve retornar estrutura de paginação correta
     */
    public function test_should_return_correct_pagination_structure(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/users-managers');

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        
        $pagination = $response->json('data.pagination');
        $this->assertArrayHasKey('current_page', $pagination);
        $this->assertArrayHasKey('per_page', $pagination);
        $this->assertArrayHasKey('total', $pagination);
        $this->assertArrayHasKey('last_page', $pagination);
    }
}








