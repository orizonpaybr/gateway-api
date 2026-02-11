<?php

namespace Tests\Unit\Controllers;

use Tests\TestCase;
use App\Models\User;
use App\Models\UsersKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes Unitários - Admin Users Actions
 * 
 * Cobre:
 * - approveUser
 * - toggleBlockUser
 * - toggleWithdrawBlock
 * - adjustBalance
 * - deleteUser
 * - showUser
 * - updateUser
 */
class AdminUsersActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $targetUser;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Criar usuário admin
        $this->adminUser = AuthTestHelper::createTestUser([
            'username' => 'admin_' . uniqid(),
            'email' => 'admin_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'status' => 1,
            'banido' => 0,
            'permission' => 3, // Admin
        ]);

        // Criar usuário alvo para testes
        $this->targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'status' => 0, // Pendente
            'banido' => 0,
            'permission' => 1, // Cliente
        ]);
    }

    public function test_should_approve_user()
    {
        $service = app(\App\Services\AdminUserService::class);
        
        $userData = $service->approveUser($this->targetUser->id);

        $this->assertNotNull($userData);
        $this->assertEquals(1, $userData->status);
        $this->assertEquals(1, $userData->aprovado_alguma_vez);
    }

    public function test_should_toggle_block_user()
    {
        $service = app(\App\Services\AdminUserService::class);
        
        // Bloquear usuário
        $userData = $service->toggleUserBlock($this->targetUser->id, true, false);
        $this->assertEquals(1, $userData->banido);

        // Desbloquear usuário
        $userData = $service->toggleUserBlock($this->targetUser->id, false, false);
        $this->assertEquals(0, $userData->banido);
    }

    public function test_should_toggle_block_user_with_approve()
    {
        $service = app(\App\Services\AdminUserService::class);
        
        // Desbloquear e aprovar usuário pendente
        $userData = $service->toggleUserBlock($this->targetUser->id, false, true);
        $this->assertEquals(0, $userData->banido);
        $this->assertEquals(1, $userData->status);
    }

    public function test_should_toggle_withdraw_block()
    {
        $service = app(\App\Services\AdminUserService::class);
        
        // Bloquear saque
        $userData = $service->toggleWithdrawBlock($this->targetUser->id, true);
        $this->assertEquals(1, $userData->saque_bloqueado);

        // Desbloquear saque
        $userData = $service->toggleWithdrawBlock($this->targetUser->id, false);
        $this->assertEquals(0, $userData->saque_bloqueado);
    }

    public function test_should_adjust_balance_add()
    {
        $service = app(\App\Services\AdminUserService::class);
        
        $initialBalance = $this->targetUser->saldo ?? 0;
        $amount = 100.00;

        $userData = $service->adjustBalance(
            $this->targetUser->id,
            $amount,
            'add',
            'Teste de ajuste'
        );

        $this->assertEquals($initialBalance + $amount, $userData->saldo);
    }

    public function test_should_adjust_balance_subtract()
    {
        // Definir saldo inicial
        $this->targetUser->saldo = 200.00;
        $this->targetUser->save();

        $service = app(\App\Services\AdminUserService::class);
        $amount = 50.00;

        $userData = $service->adjustBalance(
            $this->targetUser->id,
            $amount,
            'subtract',
            'Teste de ajuste'
        );

        $this->assertEquals(150.00, $userData->saldo);
    }

    public function test_should_not_adjust_balance_below_zero()
    {
        // Definir saldo inicial
        $this->targetUser->saldo = 50.00;
        $this->targetUser->save();

        $service = app(\App\Services\AdminUserService::class);
        $amount = 100.00; // Mais que o saldo disponível

        // Deve lançar exceção ao tentar subtrair mais que o saldo disponível
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Saldo não pode ser negativo');

        $service->adjustBalance(
            $this->targetUser->id,
            $amount,
            'subtract',
            'Teste de ajuste'
        );
    }

    public function test_should_get_user_by_id()
    {
        $service = app(\App\Services\AdminUserService::class);
        
        $user = $service->getUserById($this->targetUser->id, true);

        $this->assertNotNull($user);
        $this->assertEquals($this->targetUser->id, $user->id);
    }

    public function test_should_get_user_with_relations()
    {
        $service = app(\App\Services\AdminUserService::class);
        
        $user = $service->getUserById($this->targetUser->id, true);

        $this->assertNotNull($user);
        // Verificar se relações foram carregadas (se existirem)
        $this->assertInstanceOf(User::class, $user);
    }

    public function test_should_delete_user()
    {
        // Criar usuário com status ativo para testar delete
        $activeUser = AuthTestHelper::createTestUser([
            'username' => 'active_' . uniqid(),
            'email' => 'active_' . uniqid() . '@example.com',
            'status' => 1, // Ativo
        ]);

        $userId = $activeUser->id;
        $service = app(\App\Services\AdminUserService::class);
        
        $service->deleteUser($userId);

        // O método não deleta fisicamente, apenas marca como inativo
        $deletedUser = User::find($userId);
        $this->assertNotNull($deletedUser);
        $this->assertEquals(0, $deletedUser->status); // UserStatus::INACTIVE = 0
        $this->assertEquals(0, $deletedUser->banido);
    }

    public function test_should_update_user()
    {
        $service = app(\App\Services\AdminUserService::class);
        
        $updateData = [
            'name' => 'Nome Atualizado',
            'email' => 'novoemail_' . uniqid() . '@test.com',
        ];

        $user = $service->updateUser($this->targetUser->id, $updateData);

        $this->assertEquals('Nome Atualizado', $user->name);
        $this->assertEquals($updateData['email'], $user->email);
    }

    public function test_should_create_user()
    {
        $service = app(\App\Services\AdminUserService::class);
        
        $userData = [
            'name' => 'Novo Usuário',
            'email' => 'novo_' . uniqid() . '@test.com',
            'username' => 'novo_' . uniqid(),
            'password' => 'password123',
            'cpf_cnpj' => '12345678900',
            'status' => 0,
            'permission' => 1,
        ];

        $user = $service->createUser($userData);

        $this->assertNotNull($user);
        $this->assertEquals('Novo Usuário', $user->name);
        $this->assertEquals($userData['email'], $user->email);
    }

    public function test_should_handle_approve_user_controller()
    {
        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->approveUser($request, $this->targetUser->id);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_handle_toggle_block_user_controller()
    {
        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['block' => true]);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->toggleBlockUser($request, $this->targetUser->id);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_handle_toggle_withdraw_block_controller()
    {
        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['block' => true]);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->toggleWithdrawBlock($request, $this->targetUser->id);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_handle_adjust_balance_controller()
    {
        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge([
            'amount' => 100.00,
            'type' => 'add',
            'reason' => 'Teste de ajuste',
        ]);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->adjustBalance($request, $this->targetUser->id);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_handle_show_user_controller()
    {
        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->showUser($request, $this->targetUser->id);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('user', $data['data']);
    }

    public function test_should_handle_delete_user_controller()
    {
        $userId = $this->targetUser->id;
        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->deleteUser($request, $userId);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_validate_adjust_balance_amount()
    {
        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge([
            'amount' => -10, // Valor negativo
            'type' => 'add',
        ]);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->adjustBalance($request, $this->targetUser->id);

        // Deve retornar erro de validação
        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function test_should_validate_adjust_balance_type()
    {
        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge([
            'amount' => 100.00,
            'type' => 'invalid', // Tipo inválido
        ]);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->adjustBalance($request, $this->targetUser->id);

        // Deve retornar erro de validação
        $this->assertNotEquals(200, $response->getStatusCode());
    }
}

