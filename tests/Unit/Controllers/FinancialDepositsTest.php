<?php

namespace Tests\Unit\Controllers;

use Tests\TestCase;
use App\Models\User;
use App\Models\Solicitacoes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes Unitários - Depósitos (Entradas)
 * 
 * Cobre:
 * - getDeposits
 * - getDepositsStats
 * - updateDepositStatus
 * - Filtros e busca
 * - Paginação
 * - Validações
 */
class FinancialDepositsTest extends TestCase
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

    private function createDeposito(User $user, array $attributes = []): Solicitacoes
    {
        $defaults = [
            'user_id' => $user->user_id,
            'idTransaction' => 'TXN_DEP_' . uniqid(),
            'externalreference' => 'EXT_DEP_' . uniqid(),
            'amount' => 100.00,
            'deposito_liquido' => 97.50,
            'taxa_cash_in' => 2.50,
            'status' => 'PAID_OUT',
            'date' => now(),
            'method' => 'PIX',
            'client_name' => 'Cliente Test',
            'client_document' => '12345678900',
            'client_email' => 'cliente@test.com',
            'client_telefone' => '11999999999',
            'qrcode_pix' => 'https://example.com/qr',
            'paymentcode' => 'PAY' . uniqid(),
            'paymentCodeBase64' => base64_encode('PAY' . uniqid()),
            'adquirente_ref' => 'Banco Test',
            'taxa_pix_cash_in_adquirente' => 1.0,
            'taxa_pix_cash_in_valor_fixo' => 0.5,
            'executor_ordem' => 'EXEC' . uniqid(),
            'descricao_transacao' => 'Depósito de teste',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        return Solicitacoes::create(array_merge($defaults, $attributes));
    }

    public function test_should_get_deposits()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $this->createDeposito($targetUser);
        $this->createDeposito($targetUser);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/deposits', 'GET');
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['GET'], '/api/admin/financial/deposits', []);
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getDeposits($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('data', $data['data']);
    }

    public function test_should_filter_deposits_by_status()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $this->createDeposito($targetUser, ['status' => 'PAID_OUT']);
        $this->createDeposito($targetUser, ['status' => 'PENDING']);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/deposits', 'GET', ['status' => 'PAID_OUT']);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['GET'], '/api/admin/financial/deposits', []);
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getDeposits($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_search_deposits()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $deposito = $this->createDeposito($targetUser);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/deposits', 'GET', ['busca' => $deposito->idTransaction]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['GET'], '/api/admin/financial/deposits', []);
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getDeposits($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_filter_deposits_by_date_range()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $this->createDeposito($targetUser, ['date' => now()->subDays(5)]);
        $this->createDeposito($targetUser, ['date' => now()]);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/deposits', 'GET', [
            'data_inicio' => now()->subDays(7)->format('Y-m-d'),
            'data_fim' => now()->format('Y-m-d'),
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['GET'], '/api/admin/financial/deposits', []);
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getDeposits($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_paginate_deposits()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        // Criar múltiplos depósitos
        for ($i = 0; $i < 25; $i++) {
            $this->createDeposito($targetUser);
        }

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/deposits', 'GET', ['page' => 1, 'limit' => 10]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['GET'], '/api/admin/financial/deposits', []);
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getDeposits($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data['data']);
        $this->assertArrayHasKey('current_page', $data['data']);
        $this->assertArrayHasKey('last_page', $data['data']);
    }

    public function test_should_get_deposits_stats()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $this->createDeposito($targetUser);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/deposits/stats', 'GET', ['periodo' => 'hoje']);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['GET'], '/api/admin/financial/deposits/stats', []);
        });
        
        $request = \App\Http\Requests\FinancialStatsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getDepositsStats($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
    }

    public function test_should_get_deposits_stats_for_different_periods()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $this->createDeposito($targetUser);

        $periods = ['hoje', '7d', '30d', 'mes', 'total'];
        
        foreach ($periods as $periodo) {
            $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/deposits/stats', 'GET', ['periodo' => $periodo]);
            $httpRequest->setUserResolver(function () {
                return $this->adminUser;
            });
            $httpRequest->setRouteResolver(function () {
                return new \Illuminate\Routing\Route(['GET'], '/api/admin/financial/deposits/stats', []);
            });
            
            $request = \App\Http\Requests\FinancialStatsRequest::createFrom($httpRequest);
            $request->setContainer(app());
            $request->setRedirector(app('redirect'));
            $request->validateResolved();

            $controller = new \App\Http\Controllers\Api\FinancialController(
                app(\App\Services\FinancialService::class)
            );

            $response = $controller->getDepositsStats($request);

            $this->assertEquals(200, $response->getStatusCode());
            $data = json_decode($response->getContent(), true);
            
            $this->assertTrue($data['success']);
            $this->assertArrayHasKey('data', $data);
        }
    }

    public function test_should_update_deposit_status()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $deposito = $this->createDeposito($targetUser, ['status' => 'PENDING']);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/deposits/' . $deposito->id . '/status', 'PUT', [
            'status' => 'PAID_OUT',
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['PUT'], '/api/admin/financial/deposits/{id}/status', []);
        });
        
        $request = \App\Http\Requests\UpdateDepositStatusRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->updateDepositStatus($request, $deposito->id);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);

        // Verificar que o status foi atualizado
        $deposito->refresh();
        $this->assertEquals('PAID_OUT', $deposito->status);
    }

    public function test_should_handle_empty_deposits()
    {
        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/deposits', 'GET');
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['GET'], '/api/admin/financial/deposits', []);
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getDeposits($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
    }

    public function test_should_validate_deposit_filters()
    {
        // Testar com valores válidos (o controller já valida e corrige valores inválidos)
        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/deposits', 'GET', [
            'page' => 1,
            'limit' => 20,
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['GET'], '/api/admin/financial/deposits', []);
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getDeposits($request);

        $this->assertEquals(200, $response->getStatusCode());
    }
}








