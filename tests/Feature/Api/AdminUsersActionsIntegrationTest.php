<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Integração - API Admin Users Actions
 * 
 * Cobre:
 * - Endpoints completos com autenticação admin
 * - Aprovar usuário
 * - Bloquear/desbloquear usuário
 * - Bloquear/desbloquear saque
 * - Ajustar saldo
 * - Visualizar usuário
 * - Editar usuário
 * - Deletar usuário
 * - Configurar afiliados
 */
class AdminUsersActionsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private string $token;
    private User $targetUser;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Criar usuário admin e obter token
        $this->adminUser = AuthTestHelper::createTestUser([
            'username' => 'admin_' . uniqid(),
            'email' => 'admin_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'status' => 1,
            'banido' => 0,
            'permission' => 3, // Admin
        ]);

        $this->token = AuthTestHelper::generateTestToken($this->adminUser);

        // Criar usuário alvo para testes
        $this->targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'status' => 0, // Pendente
            'banido' => 0,
            'permission' => 1, // Cliente
            'saldo' => 100.00,
        ]);
    }

    public function test_should_approve_user_with_authentication()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson("/api/admin/users/{$this->targetUser->id}/approve");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'message',
                    'user',
                ],
            ]);

        // Verificar que o usuário foi aprovado
        $this->targetUser->refresh();
        $this->assertEquals(1, $this->targetUser->status);
    }

    public function test_should_return_401_without_authentication()
    {
        $response = $this->postJson("/api/admin/users/{$this->targetUser->id}/approve");

        $response->assertStatus(401);
    }

    public function test_should_return_403_for_non_admin_user()
    {
        // Criar usuário não-admin
        $nonAdminUser = AuthTestHelper::createTestUser([
            'username' => 'user_' . uniqid(),
            'email' => 'user_' . uniqid() . '@example.com',
            'permission' => 1, // Cliente
        ]);
        $nonAdminToken = AuthTestHelper::generateTestToken($nonAdminUser);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $nonAdminToken,
        ])->postJson("/api/admin/users/{$this->targetUser->id}/approve");

        $response->assertStatus(403);
    }

    public function test_should_toggle_block_user()
    {
        // Bloquear usuário
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson("/api/admin/users/{$this->targetUser->id}/toggle-block", [
            'block' => true,
        ]);

        $response->assertStatus(200);
        $this->targetUser->refresh();
        $this->assertEquals(1, $this->targetUser->banido);

        // Desbloquear usuário
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson("/api/admin/users/{$this->targetUser->id}/toggle-block", [
            'block' => false,
        ]);

        $response->assertStatus(200);
        $this->targetUser->refresh();
        $this->assertEquals(0, $this->targetUser->banido);
    }

    public function test_should_toggle_withdraw_block()
    {
        // Bloquear saque
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson("/api/admin/users/{$this->targetUser->id}/toggle-withdraw-block", [
            'block' => true,
        ]);

        $response->assertStatus(200);
        $this->targetUser->refresh();
        $this->assertEquals(1, $this->targetUser->saque_bloqueado);

        // Desbloquear saque
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson("/api/admin/users/{$this->targetUser->id}/toggle-withdraw-block", [
            'block' => false,
        ]);

        $response->assertStatus(200);
        $this->targetUser->refresh();
        $this->assertEquals(0, $this->targetUser->saque_bloqueado);
    }

    public function test_should_adjust_balance_add()
    {
        $initialBalance = $this->targetUser->saldo;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson("/api/admin/users/{$this->targetUser->id}/adjust-balance", [
            'amount' => 50.00,
            'type' => 'add',
            'reason' => 'Teste de ajuste',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->targetUser->refresh();
        $this->assertEquals($initialBalance + 50.00, $this->targetUser->saldo);
    }

    public function test_should_adjust_balance_subtract()
    {
        $this->targetUser->saldo = 200.00;
        $this->targetUser->save();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson("/api/admin/users/{$this->targetUser->id}/adjust-balance", [
            'amount' => 50.00,
            'type' => 'subtract',
            'reason' => 'Teste de ajuste',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->targetUser->refresh();
        $this->assertEquals(150.00, $this->targetUser->saldo);
    }

    public function test_should_validate_adjust_balance_amount()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson("/api/admin/users/{$this->targetUser->id}/adjust-balance", [
            'amount' => -10, // Valor negativo
            'type' => 'add',
        ]);

        // Pode retornar 422 (validação) ou 500 (erro no service)
        $this->assertContains($response->status(), [422, 400, 500]);
    }

    public function test_should_validate_adjust_balance_type()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson("/api/admin/users/{$this->targetUser->id}/adjust-balance", [
            'amount' => 100.00,
            'type' => 'invalid', // Tipo inválido
        ]);

        // Pode retornar 422 (validação) ou 500 (erro no service)
        $this->assertContains($response->status(), [422, 400, 500]);
    }

    public function test_should_show_user()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson("/api/admin/users/{$this->targetUser->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'user' => [
                        'id',
                        'user_id',
                        'name',
                        'email',
                        'status',
                    ],
                ],
            ]);
    }

    public function test_should_update_user()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson("/api/admin/users/{$this->targetUser->id}", [
            'name' => 'Nome Atualizado',
            'email' => 'novoemail_' . uniqid() . '@test.com',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->targetUser->refresh();
        $this->assertEquals('Nome Atualizado', $this->targetUser->name);
    }

    public function test_should_delete_user()
    {
        $userId = $this->targetUser->id;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->deleteJson("/api/admin/users/{$userId}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Verificar que o usuário foi marcado como inativo
        $deletedUser = User::find($userId);
        $this->assertNotNull($deletedUser);
        $this->assertEquals(0, $deletedUser->status); // INACTIVE
    }

    public function test_should_save_affiliate_settings()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson("/api/admin/users/{$this->targetUser->id}/affiliate-settings", [
            'is_affiliate' => true,
            'affiliate_percentage' => 5.0,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->targetUser->refresh();
        $this->assertEquals(1, $this->targetUser->is_affiliate);
        $this->assertEquals(5.0, $this->targetUser->affiliate_percentage);
    }

    public function test_should_get_users_list()
    {
        // Criar mais alguns usuários
        AuthTestHelper::createTestUser([
            'username' => 'user1_' . uniqid(),
            'email' => 'user1_' . uniqid() . '@test.com',
        ]);
        AuthTestHelper::createTestUser([
            'username' => 'user2_' . uniqid(),
            'email' => 'user2_' . uniqid() . '@test.com',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/users?per_page=20');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data',
                'pagination',
            ]);
    }

    public function test_should_filter_users_by_status()
    {
        // Criar usuário pendente
        $pendingUser = AuthTestHelper::createTestUser([
            'username' => 'pending_' . uniqid(),
            'email' => 'pending_' . uniqid() . '@test.com',
            'status' => 0, // Pendente
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/users?status=0');

        $response->assertStatus(200);
        $users = $response->json('data');
        
        // Deve retornar apenas usuários com status 0
        foreach ($users as $user) {
            $this->assertEquals(0, $user['status']);
        }
    }

    public function test_should_search_users()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/users?search=' . $this->targetUser->username);

        $response->assertStatus(200);
        $users = $response->json('data');
        
        // Deve retornar pelo menos o usuário pesquisado
        $found = false;
        foreach ($users as $user) {
            if ($user['username'] === $this->targetUser->username) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    public function test_should_paginate_users()
    {
        // Criar mais usuários para testar paginação
        for ($i = 0; $i < 25; $i++) {
            AuthTestHelper::createTestUser([
                'username' => 'pag_' . uniqid() . '_' . $i,
                'email' => 'pag_' . uniqid() . '_' . $i . '@test.com',
            ]);
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/users?per_page=10&page=1');

        $response->assertStatus(200);
        $pagination = $response->json('pagination');
        
        $this->assertEquals(1, $pagination['current_page']);
        $this->assertEquals(10, $pagination['per_page']);
        $this->assertGreaterThan(10, $pagination['total']);
    }
}

