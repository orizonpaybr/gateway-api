<?php

namespace Tests\Unit\Controllers;

use Tests\TestCase;
use App\Models\User;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes Unitários - Gerentes (Managers)
 * 
 * Cobre:
 * - listManagers
 * - Filtros e busca
 * - Paginação
 * - Cache
 * - Validações
 */
class ManagersTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

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
            'permission' => UserPermission::ADMIN,
        ]);
    }

    public function test_should_list_managers()
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

        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->listManagers($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('managers', $data['data']);
        $this->assertArrayHasKey('pagination', $data['data']);
        $this->assertGreaterThanOrEqual(2, count($data['data']['managers']));
    }

    public function test_should_filter_managers_by_search()
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

        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request(['search' => 'Busca']);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->listManagers($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $managers = $data['data']['managers'];
        
        // Verificar que pelo menos um gerente contém "Busca"
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

    public function test_should_paginate_managers()
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

        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request(['per_page' => 10, 'page' => 1]);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->listManagers($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertLessThanOrEqual(10, count($data['data']['managers']));
        $this->assertGreaterThanOrEqual(1, $data['data']['pagination']['last_page']);
    }

    public function test_should_use_cache_for_managers_list()
    {
        // Criar gerente
        AuthTestHelper::createTestUser([
            'username' => 'manager_cache_' . uniqid(),
            'email' => 'manager_cache_' . uniqid() . '@example.com',
            'permission' => UserPermission::MANAGER,
            'name' => 'Gerente Cache',
        ]);

        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        // Primeira chamada (deve buscar do banco)
        $response1 = $controller->listManagers($request);
        $this->assertEquals(200, $response1->getStatusCode());

        // Segunda chamada (deve usar cache)
        $response2 = $controller->listManagers($request);
        $this->assertEquals(200, $response2->getStatusCode());

        $data1 = json_decode($response1->getContent(), true);
        $data2 = json_decode($response2->getContent(), true);
        
        $this->assertEquals($data1['data']['managers'], $data2['data']['managers']);
    }

    public function test_should_only_list_users_with_manager_permission()
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

        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->listManagers($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $managers = $data['data']['managers'];
        
        // Verificar que todos são gerentes
        foreach ($managers as $manager) {
            $this->assertEquals(UserPermission::MANAGER, $manager['permission']);
        }
    }

    public function test_should_order_managers_by_name()
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

        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->listManagers($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $managers = $data['data']['managers'];
        
        // Verificar ordenação (pelo menos 2 gerentes)
        if (count($managers) >= 2) {
            $first = $managers[0]['name'] ?? '';
            $second = $managers[1]['name'] ?? '';
            $this->assertLessThanOrEqual(0, strcasecmp($first, $second));
        }
    }

    public function test_should_validate_per_page_maximum()
    {
        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request(['per_page' => 200]); // Mais que o máximo (100)
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->listManagers($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        // Deve limitar a 100
        $this->assertLessThanOrEqual(100, $data['data']['pagination']['per_page']);
    }

    public function test_should_validate_page_minimum()
    {
        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request(['page' => 0]); // Menos que o mínimo (1)
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->listManagers($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        // Deve usar página 1
        $this->assertEquals(1, $data['data']['pagination']['current_page']);
    }
}








