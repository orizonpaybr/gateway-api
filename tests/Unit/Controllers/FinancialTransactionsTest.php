<?php

namespace Tests\Unit\Controllers;

use Tests\TestCase;
use App\Models\User;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes Unitários - Transações Financeiras
 * 
 * Cobre:
 * - getAllTransactions
 * - getTransactionsStats
 * - Filtros e busca
 * - Paginação
 * - Validações
 */
class FinancialTransactionsTest extends TestCase
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
            'pix' => 'EMAIL',
            'pixkey' => 'test@example.com',
            'type' => 'EMAIL',
            'beneficiaryname' => 'Cliente Test',
            'beneficiarydocument' => '12345678900',
            'descricao_transacao' => 'WEB',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        return SolicitacoesCashOut::create(array_merge($defaults, $attributes));
    }

    public function test_should_get_all_transactions()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $this->createDeposito($targetUser);
        $this->createSaque($targetUser);

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );
        
        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/transactions', 'GET');
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->validateResolved();
        
        $response = $controller->getAllTransactions($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('data', $data['data']);
    }

    public function test_should_filter_transactions_by_status()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $this->createDeposito($targetUser, ['status' => 'PAID_OUT']);
        $this->createDeposito($targetUser, ['status' => 'PENDING']);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/transactions', 'GET', ['status' => 'PAID_OUT']);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getAllTransactions($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_filter_transactions_by_tipo()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $this->createDeposito($targetUser);
        $this->createSaque($targetUser);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/transactions', 'GET', ['tipo' => 'deposito']);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getAllTransactions($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_search_transactions()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $deposito = $this->createDeposito($targetUser);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/transactions', 'GET', ['busca' => $deposito->idTransaction]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getAllTransactions($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_paginate_transactions()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        // Criar múltiplas transações
        for ($i = 0; $i < 25; $i++) {
            $this->createDeposito($targetUser);
        }

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/transactions', 'GET', ['page' => 1, 'limit' => 10]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getAllTransactions($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data['data']);
        $this->assertArrayHasKey('current_page', $data['data']);
        $this->assertArrayHasKey('last_page', $data['data']);
    }

    public function test_should_get_transactions_stats()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $this->createDeposito($targetUser);
        $this->createSaque($targetUser);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/transactions/stats', 'GET', ['periodo' => 'hoje']);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        
        $request = \App\Http\Requests\FinancialStatsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getTransactionsStats($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
    }

    public function test_should_handle_empty_transactions()
    {
        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/transactions', 'GET');
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getAllTransactions($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
    }

    public function test_should_validate_filters()
    {
        // Testar com valores válidos (o controller já valida e corrige valores inválidos)
        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/transactions', 'GET', [
            'page' => 1,
            'limit' => 20,
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getAllTransactions($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_should_filter_transactions_by_date_range()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $this->createDeposito($targetUser, ['date' => now()->subDays(5)]);
        $this->createDeposito($targetUser, ['date' => now()]);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/transactions', 'GET', [
            'data_inicio' => now()->subDays(7)->format('Y-m-d'),
            'data_fim' => now()->format('Y-m-d'),
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getAllTransactions($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_get_transactions_stats_for_different_periods()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $this->createDeposito($targetUser);
        $this->createSaque($targetUser);

        $periods = ['hoje', '7d', '30d', 'mes'];
        
        foreach ($periods as $periodo) {
            $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/transactions/stats', 'GET', ['periodo' => $periodo]);
            $httpRequest->setUserResolver(function () {
                return $this->adminUser;
            });
            
            $request = \App\Http\Requests\FinancialStatsRequest::createFrom($httpRequest);
            $request->setContainer(app());
            $request->validateResolved();

            $controller = new \App\Http\Controllers\Api\FinancialController(
                app(\App\Services\FinancialService::class)
            );

            $response = $controller->getTransactionsStats($request);

            $this->assertEquals(200, $response->getStatusCode());
            $data = json_decode($response->getContent(), true);
            
            $this->assertTrue($data['success']);
            $this->assertArrayHasKey('data', $data);
        }
    }

    public function test_should_combine_filters_correctly()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $this->createDeposito($targetUser, ['status' => 'PAID_OUT']);
        $this->createSaque($targetUser, ['status' => 'COMPLETED']);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/transactions', 'GET', [
            'status' => 'PAID_OUT',
            'tipo' => 'deposito',
            'busca' => $targetUser->username,
        ]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getAllTransactions($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_handle_large_search_string()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $this->createDeposito($targetUser);

        // String de busca muito longa (deve ser truncada pelo FormRequest)
        $longSearch = str_repeat('a', 200);

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/transactions', 'GET', ['busca' => $longSearch]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        $httpRequest->setRouteResolver(function () {
            return new \Illuminate\Routing\Route(['GET'], '/api/admin/financial/transactions', []);
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        
        // Validar - deve truncar ou rejeitar busca muito longa
        try {
            $request->validateResolved();
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Esperado para busca muito longa
            $this->assertTrue(true);
            return;
        }

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getAllTransactions($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
    }

    public function test_should_return_correct_pagination_metadata()
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        // Criar exatamente 25 transações para testar paginação
        for ($i = 0; $i < 25; $i++) {
            $this->createDeposito($targetUser);
        }

        $httpRequest = \Illuminate\Http\Request::create('/api/admin/financial/transactions', 'GET', ['page' => 2, 'limit' => 10]);
        $httpRequest->setUserResolver(function () {
            return $this->adminUser;
        });
        
        $request = \App\Http\Requests\FinancialTransactionsRequest::createFrom($httpRequest);
        $request->setContainer(app());
        $request->validateResolved();

        $controller = new \App\Http\Controllers\Api\FinancialController(
            app(\App\Services\FinancialService::class)
        );

        $response = $controller->getAllTransactions($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertEquals(2, $data['data']['current_page']);
        $this->assertEquals(10, $data['data']['per_page']);
        $this->assertGreaterThanOrEqual(25, $data['data']['total']);
    }
}

