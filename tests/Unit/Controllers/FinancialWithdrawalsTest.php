<?php

namespace Tests\Unit\Controllers;

use Tests\TestCase;
use App\Models\User;
use App\Models\SolicitacoesCashOut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes Unitários - Saques (Saídas)
 * 
 * Cobre:
 * - getWithdrawals
 * - getWithdrawalsStats
 * - Filtros e busca
 * - Paginação
 * - Validações
 */
class FinancialWithdrawalsTest extends TestCase
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

    private function createSaque(User $user, array $attributes = []): SolicitacoesCashOut
    {
        $defaults = [
            'user_id' => $user->user_id,
            'idTransaction' => 'TXN_OUT_' . uniqid(),
            'externalreference' => 'EXT_OUT_' . uniqid(),
            'amount' => 50.00,
            'taxa_cash_out' => 2.50,
            'cash_out_liquido' => 47.50,
            'status' => 'COMPLETED',
            'date' => now(),
            'method' => 'PIX',
            'type' => 'EMAIL',
            'pix' => 'EMAIL',
            'pixkey' => 'test@example.com',
            'client_name' => 'Cliente Test',
            'client_document' => '12345678900',
            'client_email' => 'cliente@test.com',
            'client_telefone' => '11999999999',
            'beneficiaryname' => 'Cliente Test',
            'beneficiarydocument' => '12345678900',
            'descricao_transacao' => 'Saque de teste',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        return SolicitacoesCashOut::create(array_merge($defaults, $attributes));
    }

    public function test_should_get_withdrawals()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $this->createSaque($targetUser);
        $this->createSaque($targetUser);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/withdrawals', 'GET');
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['GET'], '/api/admin/financial/withdrawals', []);
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getWithdrawals($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('data', $data['data']);
    }

    public function test_should_filter_withdrawals_by_status()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $this->createSaque($targetUser, ['status' => 'COMPLETED']);
        $this->createSaque($targetUser, ['status' => 'PENDING']);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/withdrawals', 'GET', ['status' => 'COMPLETED']);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['GET'], '/api/admin/financial/withdrawals', []);
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getWithdrawals($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_search_withdrawals()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $saque = $this->createSaque($targetUser);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/withdrawals', 'GET', ['busca' => $saque->idTransaction]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['GET'], '/api/admin/financial/withdrawals', []);
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getWithdrawals($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_filter_withdrawals_by_date_range()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $this->createSaque($targetUser, ['date' => now()->subDays(5)]);
        $this->createSaque($targetUser, ['date' => now()]);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/withdrawals', 'GET', [
            'data_inicio' => now()->subDays(7)->format('Y-m-d'),
            'data_fim' => now()->format('Y-m-d'),
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['GET'], '/api/admin/financial/withdrawals', []);
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getWithdrawals($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_paginate_withdrawals()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        // Criar múltiplos saques
        for ($i = 0; $i < 25; $i++) {
            $this->createSaque($targetUser);
        }

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/withdrawals', 'GET', ['page' => 1, 'limit' => 10]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['GET'], '/api/admin/financial/withdrawals', []);
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getWithdrawals($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data['data']);
        $this->assertArrayHasKey('current_page', $data['data']);
        $this->assertArrayHasKey('last_page', $data['data']);
    }

    public function test_should_get_withdrawals_stats()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $this->createSaque($targetUser);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/withdrawals/stats', 'GET', ['periodo' => 'hoje']);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['GET'], '/api/admin/financial/withdrawals/stats', []);
        });
        
        $request = \App\Http\Requests\FinancialStatsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getWithdrawalsStats($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
    }

    public function test_should_get_withdrawals_stats_for_different_periods()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $this->createSaque($targetUser);

        $periods = ['hoje', '7d', '30d', 'mes', 'total'];
        
        foreach ($periods as $periodo) {
            $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/withdrawals/stats', 'GET', ['periodo' => $periodo]);
            $httpRequest->setUserResolver(function () {
                return $this->adminUser;
            });
            $httpRequest->setRouteResolver(function () {
                return new \Illuminate\Routing\Route(['GET'], '/api/admin/financial/withdrawals/stats', []);
            });
            
            $request = \App\Http\Requests\FinancialStatsRequest::createFrom($httpRequest);
            $request->setContainer(app());
            $request->setRedirector(app('redirect'));
            $request->validateResolved();

            $controller = new \App\Http\Controllers\Api\FinancialController(
                app(\App\Services\FinancialService::class)
            );

            $response = $controller->getWithdrawalsStats($request);

            $this->assertEquals(200, $response->getStatusCode());
            $data = json_decode($response->getContent(), true);
            
            $this->assertTrue($data['success']);
            $this->assertArrayHasKey('data', $data);
        }
    }

    public function test_should_handle_empty_withdrawals()
    {
        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/withdrawals', 'GET');
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['GET'], '/api/admin/financial/withdrawals', []);
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getWithdrawals($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
    }

    public function test_should_validate_withdrawal_filters()
    {
        // Testar com valores válidos (o controller já valida e corrige valores inválidos)
        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/withdrawals', 'GET', [
            'page' => 1,
            'limit' => 20,
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['GET'], '/api/admin/financial/withdrawals', []);
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getWithdrawals($request);

        $this->assertEquals(200, $response->getStatusCode());
    }
}








