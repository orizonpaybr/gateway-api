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
 * Testes Unitários - Admin Dashboard Controller
 * 
 * Cobre:
 * - getDashboardStats
 * - getUserStats
 * - getCacheMetrics
 * - getRecentTransactions
 * - Cálculo de estatísticas financeiras
 * - Cálculo de estatísticas de transações
 * - Cálculo de estatísticas de usuários
 * - Cache
 */
class AdminDashboardTest extends TestCase
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

    /**
     * Helper para criar depósito de teste
     */
    private function createDeposito(array $attributes = []): Solicitacoes
    {
        $defaults = [
            'user_id' => $this->adminUser->username,
            'idTransaction' => 'TXN' . uniqid(),
            'externalreference' => 'EXT' . uniqid(),
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
        ];

        return Solicitacoes::create(array_merge($defaults, $attributes));
    }

    /**
     * Helper para criar saque de teste
     */
    private function createSaque(array $attributes = []): SolicitacoesCashOut
    {
        $amount = $attributes['amount'] ?? 50.00;
        $taxa = $attributes['taxa_cash_out'] ?? 2.00;
        $cashOutLiquido = $amount - $taxa;

        $defaults = [
            'user_id' => $this->adminUser->username,
            'idTransaction' => 'TXN_OUT' . uniqid(),
            'externalreference' => 'EXT_OUT' . uniqid(),
            'amount' => $amount,
            'taxa_cash_out' => $taxa,
            'cash_out_liquido' => $cashOutLiquido,
            'status' => 'COMPLETED',
            'date' => now(),
            'method' => 'PIX',
            'type' => 'pix',
            'client_name' => 'Cliente Test',
            'client_document' => '12345678900',
            'client_email' => 'cliente@test.com',
            'client_telefone' => '11999999999',
            'beneficiaryname' => 'Beneficiário Test',
            'beneficiarydocument' => '12345678900',
            'pix' => 'test@example.com',
            'pixkey' => 'test@example.com',
            'descricao_transacao' => 'Saque de teste',
        ];

        return SolicitacoesCashOut::create(array_merge($defaults, $attributes));
    }

    public function test_should_calculate_dashboard_stats_correctly()
    {
        // Criar transações
        $this->createDeposito(['amount' => 1000, 'taxa_cash_in' => 25]);
        $this->createSaque(['amount' => 500, 'taxa_cash_out' => 10]);

        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['periodo' => 'hoje']);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getDashboardStats($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('financeiro', $data['data']);
        $this->assertArrayHasKey('transacoes', $data['data']);
    }

    public function test_should_calculate_financial_stats()
    {
        // Criar depósitos e saques
        $this->createDeposito(['amount' => 1000, 'taxa_cash_in' => 25]);
        $this->createDeposito(['amount' => 2000, 'taxa_cash_in' => 50]);
        $this->createSaque(['amount' => 500, 'taxa_cash_out' => 10]);

        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['periodo' => 'hoje']);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getDashboardStats($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $financeiro = $data['data']['financeiro'];
        
        // Lucro de depósitos: 25 + 50 = 75
        $this->assertEquals(75, $financeiro['lucro_depositos']);
        // Lucro de saques: 10
        $this->assertEquals(10, $financeiro['lucro_saques']);
    }

    public function test_should_calculate_transaction_stats()
    {
        // Criar transações
        $this->createDeposito(['amount' => 1000]);
        $this->createDeposito(['amount' => 2000]);
        $this->createSaque(['amount' => 500]);

        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['periodo' => 'hoje']);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getDashboardStats($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $transacoes = $data['data']['transacoes'];
        
        $this->assertEquals(2, $transacoes['depositos']['quantidade']);
        $this->assertEquals(1, $transacoes['saques']['quantidade']);
        $this->assertEquals(3, $transacoes['total']['quantidade']);
    }

    public function test_should_calculate_user_stats()
    {
        // Criar usuários com emails únicos
        AuthTestHelper::createTestUser([
            'status' => 0,
            'email' => 'pendente_' . uniqid() . '@test.com',
            'username' => 'pendente_' . uniqid(),
        ]); // Pendente
        AuthTestHelper::createTestUser([
            'status' => 1,
            'email' => 'aprovado_' . uniqid() . '@test.com',
            'username' => 'aprovado_' . uniqid(),
        ]); // Aprovado

        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['periodo' => 'hoje']);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getDashboardStats($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $usuarios = $data['data']['usuarios'];
        
        $this->assertArrayHasKey('cadastrados', $usuarios);
        $this->assertArrayHasKey('pendentes', $usuarios);
        $this->assertArrayHasKey('aprovados', $usuarios);
    }

    public function test_should_calculate_pending_withdrawals()
    {
        // Criar saques pendentes
        $this->createSaque(['status' => 'PENDING', 'amount' => 1000]);
        $this->createSaque(['status' => 'PENDING', 'amount' => 2000]);

        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['periodo' => 'hoje']);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getDashboardStats($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $saquesPendentes = $data['data']['saques_pendentes'];
        
        $this->assertEquals(2, $saquesPendentes['quantidade']);
        $this->assertEquals(3000, $saquesPendentes['valor_total']);
    }

    public function test_should_use_cache_for_dashboard_stats()
    {
        // Criar transações
        $this->createDeposito(['amount' => 1000]);

        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['periodo' => 'hoje']);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        // Primeira chamada
        $response1 = $controller->getDashboardStats($request);
        $data1 = json_decode($response1->getContent(), true);

        // Segunda chamada (deve usar cache)
        $response2 = $controller->getDashboardStats($request);
        $data2 = json_decode($response2->getContent(), true);

        $this->assertEquals(200, $response1->getStatusCode());
        $this->assertEquals(200, $response2->getStatusCode());
        $this->assertEquals($data1['data'], $data2['data']);
    }

    public function test_should_handle_different_periods()
    {
        $periodos = ['hoje', 'ontem', '7dias', '30dias', 'mes_atual', 'mes_anterior', 'tudo'];

        foreach ($periodos as $periodo) {
            $controller = new \App\Http\Controllers\Api\AdminDashboardController(
                app(\App\Services\AdminUserService::class)
            );
            $request = new \Illuminate\Http\Request();
            $request->merge(['periodo' => $periodo]);
            $request->setUserResolver(function () {
                return $this->adminUser;
            });

            $response = $controller->getDashboardStats($request);

            $this->assertEquals(200, $response->getStatusCode(), "Falhou para período: {$periodo}");
            $data = json_decode($response->getContent(), true);
            
            $this->assertTrue($data['success']);
            $this->assertArrayHasKey('data', $data);
        }
    }

    public function test_should_get_user_stats()
    {
        // Criar usuários com emails únicos
        AuthTestHelper::createTestUser([
            'status' => 0,
            'email' => 'pendente_stats_' . uniqid() . '@test.com',
            'username' => 'pendente_stats_' . uniqid(),
        ]); // Pendente
        AuthTestHelper::createTestUser([
            'status' => 1,
            'email' => 'aprovado_stats_' . uniqid() . '@test.com',
            'username' => 'aprovado_stats_' . uniqid(),
        ]); // Aprovado
        AuthTestHelper::createTestUser([
            'banido' => true,
            'email' => 'banido_stats_' . uniqid() . '@test.com',
            'username' => 'banido_stats_' . uniqid(),
        ]); // Banido

        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getUserStats($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('total_registrations', $data['data']);
        $this->assertArrayHasKey('pending_registrations', $data['data']);
        $this->assertArrayHasKey('banned_users', $data['data']);
    }

    public function test_should_get_cache_metrics()
    {
        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getCacheMetrics($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('general', $data['data']);
        $this->assertArrayHasKey('financial', $data['data']);
    }

    public function test_should_get_recent_transactions()
    {
        // Criar transações
        $this->createDeposito(['amount' => 1000]);
        $this->createSaque(['amount' => 500]);

        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['limit' => 10]);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getRecentTransactions($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('transactions', $data['data']);
    }

    public function test_should_filter_transactions_by_type()
    {
        // Criar transações
        $this->createDeposito(['amount' => 1000]);
        $this->createSaque(['amount' => 500]);

        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['limit' => 10, 'type' => 'deposit']);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getRecentTransactions($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $transactions = $data['data']['transactions'];
        
        // Deve retornar apenas depósitos
        foreach ($transactions as $transaction) {
            $this->assertEquals('deposit', $transaction['type']);
        }
    }

    public function test_should_filter_transactions_by_status()
    {
        // Criar transações com diferentes status
        $this->createDeposito(['amount' => 1000, 'status' => 'PAID_OUT']);
        $this->createDeposito(['amount' => 2000, 'status' => 'PENDING']);

        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['limit' => 10, 'status' => 'PAID_OUT']);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getRecentTransactions($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $transactions = $data['data']['transactions'];
        
        // Deve retornar apenas transações com status PAID_OUT
        foreach ($transactions as $transaction) {
            $this->assertEquals('PAID_OUT', $transaction['status']);
        }
    }

    public function test_should_limit_transactions()
    {
        // Criar mais transações que o limite
        for ($i = 0; $i < 20; $i++) {
            $this->createDeposito(['amount' => 1000 + $i]);
        }

        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['limit' => 10]);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getRecentTransactions($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $transactions = $data['data']['transactions'];
        
        // Deve retornar no máximo 10 transações
        $this->assertLessThanOrEqual(10, count($transactions));
    }

    public function test_should_only_count_paid_deposits()
    {
        // Criar depósitos com diferentes status
        $this->createDeposito(['amount' => 1000, 'status' => 'PAID_OUT', 'taxa_cash_in' => 25]);
        $this->createDeposito(['amount' => 2000, 'status' => 'PENDING', 'taxa_cash_in' => 50]);
        $this->createDeposito(['amount' => 3000, 'status' => 'CANCELLED', 'taxa_cash_in' => 75]);

        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['periodo' => 'hoje']);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getDashboardStats($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $financeiro = $data['data']['financeiro'];
        
        // Deve contar apenas PAID_OUT (lucro = 25)
        $this->assertEquals(25, $financeiro['lucro_depositos']);
    }

    public function test_should_only_count_completed_withdrawals()
    {
        // Criar saques com diferentes status
        $this->createSaque(['amount' => 500, 'status' => 'COMPLETED', 'taxa_cash_out' => 10]);
        $this->createSaque(['amount' => 1000, 'status' => 'PENDING', 'taxa_cash_out' => 20]);
        $this->createSaque(['amount' => 1500, 'status' => 'REJECTED', 'taxa_cash_out' => 30]);

        $controller = new \App\Http\Controllers\Api\AdminDashboardController(
            app(\App\Services\AdminUserService::class)
        );
        $request = new \Illuminate\Http\Request();
        $request->merge(['periodo' => 'hoje']);
        $request->setUserResolver(function () {
            return $this->adminUser;
        });

        $response = $controller->getDashboardStats($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        
        $this->assertTrue($data['success']);
        $financeiro = $data['data']['financeiro'];
        
        // Deve contar apenas COMPLETED (lucro = 10)
        $this->assertEquals(10, $financeiro['lucro_saques']);
    }
}

