<?php

namespace Tests\Unit\Controllers;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes Unitários - Carteiras (Wallets)
 * 
 * Cobre:
 * - getWallets
 * - getWalletsStats
 * - Filtros e busca
 * - Paginação
 * - Ordenação
 * - Validações
 */
class FinancialWalletsTest extends TestCase
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
            'permission' => 3, // Admin
        ]);
    }

    public function test_should_get_wallets()
    {
        // Criar usuários com saldo
        AuthTestHelper::createTestUser([
            'username' => 'user1_' . uniqid(),
            'email' => 'user1_' . uniqid() . '@example.com',
            'saldo' => 100.00,
        ]);
        AuthTestHelper::createTestUser([
            'username' => 'user2_' . uniqid(),
            'email' => 'user2_' . uniqid() . '@example.com',
            'saldo' => 200.00,
        ]);

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getWallets($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('data', $data['data']);
    }

    public function test_should_search_wallets()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 150.00,
        ]);

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['busca' => $targetUser->username]);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getWallets($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_filter_wallets_by_tipo_usuario()
    {
        AuthTestHelper::createTestUser([
            'username' => 'ativo_' . uniqid(),
            'email' => 'ativo_' . uniqid() . '@example.com',
            'saldo' => 100.00,
        ]);
        AuthTestHelper::createTestUser([
            'username' => 'inativo_' . uniqid(),
            'email' => 'inativo_' . uniqid() . '@example.com',
            'saldo' => 0.00,
        ]);

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['tipo_usuario' => 'ativo']);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getWallets($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_sort_wallets_by_saldo()
    {
        AuthTestHelper::createTestUser([
            'username' => 'user1_' . uniqid(),
            'email' => 'user1_' . uniqid() . '@example.com',
            'saldo' => 100.00,
        ]);
        AuthTestHelper::createTestUser([
            'username' => 'user2_' . uniqid(),
            'email' => 'user2_' . uniqid() . '@example.com',
            'saldo' => 200.00,
        ]);

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['ordenar' => 'saldo_desc']);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getWallets($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_paginate_wallets()
    {
        // Criar múltiplos usuários
        for ($i = 0; $i < 25; $i++) {
            AuthTestHelper::createTestUser([
                'username' => 'user_' . uniqid() . '_' . $i,
                'email' => 'user_' . uniqid() . '_' . $i . '@example.com',
                'saldo' => ($i + 1) * 10.00,
            ]);
        }

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['page' => 1, 'limit' => 10]);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getWallets($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data['data']);
        $this->assertArrayHasKey('current_page', $data['data']);
        $this->assertArrayHasKey('last_page', $data['data']);
    }

    public function test_should_get_wallets_stats()
    {
        AuthTestHelper::createTestUser([
            'username' => 'user1_' . uniqid(),
            'email' => 'user1_' . uniqid() . '@example.com',
            'saldo' => 100.00,
        ]);
        AuthTestHelper::createTestUser([
            'username' => 'user2_' . uniqid(),
            'email' => 'user2_' . uniqid() . '@example.com',
            'saldo' => 200.00,
        ]);

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getWalletsStats($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('total_carteiras', $data['data']);
        $this->assertArrayHasKey('saldo_total', $data['data']);
        $this->assertArrayHasKey('top_3_usuarios', $data['data']);
    }

    public function test_should_handle_empty_wallets()
    {
        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getWallets($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
    }

    public function test_should_validate_wallet_filters()
    {
        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge([
            'page' => -1, // Valor inválido
            'limit' => 200, // Valor acima do máximo
        ]);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        // O controller deve validar e corrigir valores inválidos
        $response = $controller->getWallets($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_should_filter_wallets_by_inactive_users()
    {
        AuthTestHelper::createTestUser([
            'username' => 'ativo_' . uniqid(),
            'email' => 'ativo_' . uniqid() . '@example.com',
            'saldo' => 100.00,
        ]);
        AuthTestHelper::createTestUser([
            'username' => 'inativo_' . uniqid(),
            'email' => 'inativo_' . uniqid() . '@example.com',
            'saldo' => 0.00,
        ]);

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['tipo_usuario' => 'inativo']);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getWallets($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_sort_wallets_ascending()
    {
        AuthTestHelper::createTestUser([
            'username' => 'user1_' . uniqid(),
            'email' => 'user1_' . uniqid() . '@example.com',
            'saldo' => 200.00,
        ]);
        AuthTestHelper::createTestUser([
            'username' => 'user2_' . uniqid(),
            'email' => 'user2_' . uniqid() . '@example.com',
            'saldo' => 100.00,
        ]);

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['ordenar' => 'saldo_asc']);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getWallets($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_search_wallets_by_email()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 150.00,
        ]);

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['busca' => $targetUser->email]);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getWallets($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_limit_wallets_per_page()
    {
        // Criar múltiplos usuários
        for ($i = 0; $i < 30; $i++) {
            AuthTestHelper::createTestUser([
                'username' => 'user_' . uniqid() . '_' . $i,
                'email' => 'user_' . uniqid() . '_' . $i . '@example.com',
                'saldo' => ($i + 1) * 10.00,
            ]);
        }

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['page' => 1, 'limit' => 5]);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getWallets($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertLessThanOrEqual(5, count($data['data']['data']));
    }

    public function test_should_get_top3_users_in_stats()
    {
        // Criar usuários com diferentes saldos
        AuthTestHelper::createTestUser([
            'username' => 'user1_' . uniqid(),
            'email' => 'user1_' . uniqid() . '@example.com',
            'saldo' => 300.00,
        ]);
        AuthTestHelper::createTestUser([
            'username' => 'user2_' . uniqid(),
            'email' => 'user2_' . uniqid() . '@example.com',
            'saldo' => 200.00,
        ]);
        AuthTestHelper::createTestUser([
            'username' => 'user3_' . uniqid(),
            'email' => 'user3_' . uniqid() . '@example.com',
            'saldo' => 100.00,
        ]);

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getWalletsStats($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('top_3_usuarios', $data['data']);
        $this->assertLessThanOrEqual(3, count($data['data']['top_3_usuarios']));
    }
}

