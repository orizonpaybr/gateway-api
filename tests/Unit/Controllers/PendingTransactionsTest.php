<?php

namespace Tests\Unit\Controllers;

use Tests\TestCase;
use App\Http\Controllers\Api\UserController;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Testes Unitários - Transações Pendentes
 * 
 * Cobre:
 * - Funcionalidade de busca de transações pendentes
 * - Filtros (status PENDING, busca, datas)
 * - Paginação
 * - Cache
 * - Formatação de dados
 */
class PendingTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        
        // Criar usuário para foreign key
        $this->user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'status' => 1,
            'banido' => 0,
        ]);
    }

    /**
     * Helper para criar depósito pendente
     */
    private function createDepositoPendente(array $attributes = []): Solicitacoes
    {
        $defaults = [
            'user_id' => $this->user->user_id ?? $this->user->username,
            'idTransaction' => 'TXN' . uniqid(),
            'externalreference' => 'EXT' . uniqid(),
            'amount' => 100.00,
            'deposito_liquido' => 97.50,
            'taxa_cash_in' => 2.50,
            'status' => 'PENDING',
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
            'descricao_transacao' => 'Transação pendente',
        ];

        return Solicitacoes::create(array_merge($defaults, $attributes));
    }

    /**
     * Helper para criar saque pendente
     */
    private function createSaquePendente(array $attributes = []): SolicitacoesCashOut
    {
        $defaults = [
            'user_id' => $this->user->user_id ?? $this->user->username,
            'idTransaction' => 'TXN' . uniqid(),
            'externalreference' => 'EXT' . uniqid(),
            'amount' => 100.00,
            'cash_out_liquido' => 97.50,
            'taxa_cash_out' => 2.50,
            'status' => 'PENDING',
            'date' => now(),
            'pix' => 'test@example.com',
            'pixkey' => 'test@example.com',
            'type' => 'EMAIL',
            'beneficiaryname' => 'Cliente Test',
            'beneficiarydocument' => '12345678900',
            'descricao_transacao' => 'Saque pendente',
        ];

        return SolicitacoesCashOut::create(array_merge($defaults, $attributes));
    }

    /**
     * Teste: Deve retornar apenas transações com status PENDING
     */
    public function test_should_return_only_pending_transactions(): void
    {
        // Criar transações com diferentes status
        $this->createDepositoPendente(['status' => 'PENDING']);
        $this->createDepositoPendente(['status' => 'PAID_OUT']);
        $this->createDepositoPendente(['status' => 'COMPLETED']);

        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'status' => 'PENDING',
            'page' => 1,
            'limit' => 20,
        ]);
        $request->setUserResolver(fn() => $this->user);

        $response = $controller->getTransactions($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(1, $data['data']['total']);
        $this->assertEquals('PENDING', $data['data']['data'][0]['status']);
    }

    /**
     * Teste: Deve incluir depósitos e saques pendentes
     */
    public function test_should_include_pending_deposits_and_withdrawals(): void
    {
        $this->createDepositoPendente(['idTransaction' => 'DEP001']);
        $this->createSaquePendente(['idTransaction' => 'SAQ001']);

        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'status' => 'PENDING',
            'page' => 1,
            'limit' => 20,
        ]);
        $request->setUserResolver(fn() => $this->user);

        $response = $controller->getTransactions($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(2, $data['data']['total']);
        
        $transactionIds = array_column($data['data']['data'], 'transaction_id');
        $this->assertContains('DEP001', $transactionIds);
        $this->assertContains('SAQ001', $transactionIds);
    }

    /**
     * Teste: Deve retornar lista paginada de transações pendentes
     */
    public function test_should_return_paginated_pending_transactions(): void
    {
        // Criar 25 transações pendentes
        for ($i = 0; $i < 25; $i++) {
            $this->createDepositoPendente(['amount' => 100 + $i]);
        }

        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'status' => 'PENDING',
            'page' => 1,
            'limit' => 20,
        ]);
        $request->setUserResolver(fn() => $this->user);

        $response = $controller->getTransactions($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(20, count($data['data']['data']));
        $this->assertEquals(25, $data['data']['total']);
        $this->assertEquals(2, $data['data']['last_page']);
    }

    /**
     * Teste: Deve buscar transações pendentes por termo
     */
    public function test_should_search_pending_transactions_by_term(): void
    {
        $this->createDepositoPendente(['idTransaction' => 'TXN123', 'client_name' => 'Cliente Test']);
        $this->createDepositoPendente(['idTransaction' => 'TXN456', 'client_name' => 'Outro Cliente']);

        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'status' => 'PENDING',
            'busca' => 'TXN123',
            'page' => 1,
            'limit' => 20,
        ]);
        $request->setUserResolver(fn() => $this->user);

        $response = $controller->getTransactions($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(1, $data['data']['total']);
        $this->assertEquals('TXN123', $data['data']['data'][0]['transaction_id']);
    }

    /**
     * Teste: Deve filtrar transações pendentes por intervalo de datas
     */
    public function test_should_filter_pending_transactions_by_date_range(): void
    {
        $hoje = Carbon::now()->startOfDay();
        $ontem = Carbon::yesterday()->startOfDay();

        $this->createDepositoPendente(['date' => $hoje]);
        $this->createDepositoPendente(['date' => $ontem]);
        $this->createDepositoPendente(['date' => $hoje->copy()->subDays(5)]);

        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'status' => 'PENDING',
            'data_inicio' => $hoje->format('Y-m-d'),
            'data_fim' => $hoje->copy()->endOfDay()->format('Y-m-d'),
            'page' => 1,
            'limit' => 20,
        ]);
        $request->setUserResolver(fn() => $this->user);

        $response = $controller->getTransactions($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        // Pode retornar 1 ou mais dependendo do horário exato
        $this->assertGreaterThanOrEqual(1, $data['data']['total']);
    }

    /**
     * Teste: Deve usar cache para transações pendentes
     */
    public function test_should_use_cache_for_pending_transactions(): void
    {
        $this->createDepositoPendente();

        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'status' => 'PENDING',
            'page' => 1,
            'limit' => 20,
        ]);
        $request->setUserResolver(fn() => $this->user);

        // Primeira chamada - deve buscar do banco
        $response1 = $controller->getTransactions($request);
        $data1 = json_decode($response1->getContent(), true);
        $this->assertEquals(1, $data1['data']['total']);

        // Segunda chamada - deve usar cache
        Cache::shouldReceive('remember')
            ->once()
            ->andReturn(['transactions' => $data1['data']['data'], 'total' => 1, 'total_pages' => 1]);

        $response2 = $controller->getTransactions($request);
        $data2 = json_decode($response2->getContent(), true);
        $this->assertEquals($data1['data']['total'], $data2['data']['total']);
    }

    /**
     * Teste: Deve validar limite máximo de itens por página
     */
    public function test_should_validate_max_items_per_page(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->createDepositoPendente();
        }

        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'status' => 'PENDING',
            'page' => 1,
            'limit' => 100, // Limite máximo é 50
        ]);
        $request->setUserResolver(fn() => $this->user);

        $response = $controller->getTransactions($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertLessThanOrEqual(50, count($data['data']['data']));
    }

    /**
     * Teste: Deve ordenar transações pendentes por data descendente
     */
    public function test_should_order_pending_transactions_by_date_desc(): void
    {
        $data1 = Carbon::now()->subDays(2);
        $data2 = Carbon::now()->subDays(1);
        $data3 = Carbon::now();

        $this->createDepositoPendente(['date' => $data1, 'idTransaction' => 'TXN1']);
        $this->createDepositoPendente(['date' => $data3, 'idTransaction' => 'TXN2']);
        $this->createDepositoPendente(['date' => $data2, 'idTransaction' => 'TXN3']);

        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'status' => 'PENDING',
            'page' => 1,
            'limit' => 20,
        ]);
        $request->setUserResolver(fn() => $this->user);

        $response = $controller->getTransactions($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('TXN2', $data['data']['data'][0]['transaction_id']);
        $this->assertEquals('TXN3', $data['data']['data'][1]['transaction_id']);
        $this->assertEquals('TXN1', $data['data']['data'][2]['transaction_id']);
    }

    /**
     * Teste: Deve retornar array vazio quando não há transações pendentes
     */
    public function test_should_return_empty_array_when_no_pending_transactions(): void
    {
        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'status' => 'PENDING',
            'page' => 1,
            'limit' => 20,
        ]);
        $request->setUserResolver(fn() => $this->user);

        $response = $controller->getTransactions($request);
        $data = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(0, $data['data']['total']);
        $this->assertEmpty($data['data']['data']);
    }

    /**
     * Teste: Deve formatar transação pendente corretamente
     */
    public function test_should_format_pending_transaction_correctly(): void
    {
        $deposito = $this->createDepositoPendente([
            'amount' => 100.00,
            'deposito_liquido' => 97.50,
            'status' => 'PENDING',
            'descricao_transacao' => 'Transação pendente',
        ]);

        $controller = new UserController();
        $request = \Illuminate\Http\Request::create('/api/transactions', 'GET', [
            'status' => 'PENDING',
            'page' => 1,
            'limit' => 20,
        ]);
        $request->setUserResolver(fn() => $this->user);

        $response = $controller->getTransactions($request);
        $data = json_decode($response->getContent(), true);

        $formatted = $data['data']['data'][0];

        $this->assertArrayHasKey('id', $formatted);
        $this->assertArrayHasKey('transaction_id', $formatted);
        $this->assertArrayHasKey('tipo', $formatted);
        $this->assertArrayHasKey('amount', $formatted);
        $this->assertArrayHasKey('valor_liquido', $formatted);
        $this->assertArrayHasKey('status', $formatted);
        $this->assertArrayHasKey('status_legivel', $formatted);
        $this->assertArrayHasKey('data', $formatted);
        $this->assertEquals(100.0, $formatted['amount']);
        $this->assertEquals(97.5, $formatted['valor_liquido']);
        $this->assertEquals('PENDING', $formatted['status']);
        $this->assertEquals('Pendente', $formatted['status_legivel']);
    }
}

