<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\FinancialService;
use App\Models\Solicitacoes;
use App\Models\User;
use App\Models\UsersKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Testes Unitários - FinancialService - Depósitos
 * 
 * Cobre:
 * - Funcionalidade de busca de depósitos
 * - Filtros (status, busca, datas)
 * - Paginação
 * - Cache
 * - Estatísticas
 * - Performance
 */
class FinancialServiceDepositsTest extends TestCase
{
    use RefreshDatabase;

    private FinancialService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = new FinancialService();
        
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
     * Helper para criar depósito de teste
     */
    private function createDeposito(array $attributes = []): Solicitacoes
    {
        $defaults = [
            'user_id' => $this->user->user_id ?? $this->user->username,
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
     * Teste: Deve retornar lista de depósitos paginada
     */
    public function test_should_return_paginated_deposits(): void
    {
        // Criar 25 depósitos
        for ($i = 0; $i < 25; $i++) {
            $this->createDeposito(['amount' => 100 + $i]);
        }

        $filters = ['page' => 1, 'limit' => 20];
        $result = $this->service->getDeposits($filters);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('current_page', $result);
        $this->assertArrayHasKey('last_page', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertEquals(1, $result['current_page']);
        $this->assertEquals(2, $result['last_page']);
        $this->assertEquals(25, $result['total']);
        $this->assertCount(20, $result['data']);
    }

    /**
     * Teste: Deve filtrar depósitos por status
     */
    public function test_should_filter_deposits_by_status(): void
    {
        $this->createDeposito(['status' => 'PAID_OUT', 'amount' => 100]);
        $this->createDeposito(['status' => 'PENDING', 'amount' => 200]);
        $this->createDeposito(['status' => 'PAID_OUT', 'amount' => 300]);

        $filters = ['page' => 1, 'limit' => 20, 'status' => 'PAID_OUT'];
        $result = $this->service->getDeposits($filters);

        $this->assertEquals(2, $result['total']);
        foreach ($result['data'] as $deposito) {
            $this->assertEquals('Pago', $deposito['status_legivel']);
        }
    }

    /**
     * Teste: Deve buscar depósitos por termo
     */
    public function test_should_search_deposits_by_term(): void
    {
        $this->createDeposito(['client_name' => 'João Silva', 'idTransaction' => 'TXN001']);
        $this->createDeposito(['client_name' => 'Maria Santos', 'idTransaction' => 'TXN002']);
        $this->createDeposito(['client_name' => 'João Pedro', 'idTransaction' => 'TXN003']);

        $filters = ['page' => 1, 'limit' => 20, 'busca' => 'João'];
        $result = $this->service->getDeposits($filters);

        $this->assertGreaterThanOrEqual(2, $result['total']);
    }

    /**
     * Teste: Deve filtrar depósitos por data
     */
    public function test_should_filter_deposits_by_date_range(): void
    {
        $hoje = Carbon::now();
        $ontem = $hoje->copy()->subDay();
        $amanha = $hoje->copy()->addDay();

        $this->createDeposito(['date' => $ontem, 'amount' => 100]);
        $this->createDeposito(['date' => $hoje, 'amount' => 200]);
        $this->createDeposito(['date' => $amanha, 'amount' => 300]);

        $filters = [
            'page' => 1,
            'limit' => 20,
            'data_inicio' => $hoje->format('Y-m-d'),
            'data_fim' => $hoje->format('Y-m-d'),
        ];

        $result = $this->service->getDeposits($filters);

        $this->assertGreaterThanOrEqual(1, $result['total']);
    }

    /**
     * Teste: Deve usar cache para depósitos
     */
    public function test_should_use_cache_for_deposits(): void
    {
        $this->createDeposito(['amount' => 100]);

        $filters = ['page' => 1, 'limit' => 20];
        
        // Primeira chamada - deve criar cache
        $result1 = $this->service->getDeposits($filters);
        
        // Criar novo depósito (não deve aparecer por causa do cache)
        $this->createDeposito(['amount' => 200]);
        
        // Segunda chamada - deve usar cache
        $result2 = $this->service->getDeposits($filters);

        $this->assertEquals($result1['total'], $result2['total']);
    }

    /**
     * Teste: Deve retornar estatísticas de depósitos
     */
    public function test_should_return_deposits_stats(): void
    {
        $hoje = Carbon::now();
        
        // Criar depósitos de hoje
        $this->createDeposito([
            'date' => $hoje,
            'status' => 'PAID_OUT',
            'amount' => 100.00,
        ]);
        $this->createDeposito([
            'date' => $hoje,
            'status' => 'PAID_OUT',
            'amount' => 200.00,
        ]);

        // Criar depósito pendente (não deve contar)
        $this->createDeposito([
            'date' => $hoje,
            'status' => 'PENDING',
            'amount' => 300.00,
        ]);

        $stats = $this->service->getDepositsStats('hoje');

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('depositos_aprovados_hoje', $stats);
        $this->assertArrayHasKey('valor_total_hoje', $stats);
        $this->assertGreaterThanOrEqual(2, $stats['depositos_aprovados_hoje']);
        $this->assertGreaterThanOrEqual(300.00, $stats['valor_total_hoje']);
    }

    /**
     * Teste: Deve validar limite máximo de itens por página
     */
    public function test_should_validate_max_items_per_page(): void
    {
        for ($i = 0; $i < 150; $i++) {
            $this->createDeposito(['amount' => 100 + $i]);
        }

        $filters = ['page' => 1, 'limit' => 200]; // Limite máximo é 100
        $result = $this->service->getDeposits($filters);

        $this->assertLessThanOrEqual(100, count($result['data']));
        $this->assertEquals(100, $result['per_page']);
    }

    /**
     * Teste: Deve validar página mínima
     */
    public function test_should_validate_minimum_page(): void
    {
        $this->createDeposito(['amount' => 100]);

        $filters = ['page' => 0, 'limit' => 20]; // Página mínima é 1
        $result = $this->service->getDeposits($filters);

        $this->assertEquals(1, $result['current_page']);
    }

    /**
     * Teste: Deve ordenar depósitos por data decrescente
     */
    public function test_should_order_deposits_by_date_desc(): void
    {
        $hoje = Carbon::now();
        $ontem = $hoje->copy()->subDay();
        $anteontem = $hoje->copy()->subDays(2);

        $dep1 = $this->createDeposito(['date' => $anteontem, 'amount' => 100]);
        $dep2 = $this->createDeposito(['date' => $hoje, 'amount' => 200]);
        $dep3 = $this->createDeposito(['date' => $ontem, 'amount' => 300]);

        $filters = ['page' => 1, 'limit' => 20];
        $result = $this->service->getDeposits($filters);

        $this->assertGreaterThanOrEqual(3, count($result['data']));
        // Primeiro item deve ser o mais recente
        $this->assertEquals($dep2->id, $result['data'][0]['id']);
    }

    /**
     * Teste: Deve retornar array vazio quando não há depósitos
     */
    public function test_should_return_empty_array_when_no_deposits(): void
    {
        $filters = ['page' => 1, 'limit' => 20];
        $result = $this->service->getDeposits($filters);

        $this->assertIsArray($result);
        $this->assertEmpty($result['data']);
        $this->assertEquals(0, $result['total']);
    }

    /**
     * Teste: Deve formatar depósito corretamente
     */
    public function test_should_format_deposit_correctly(): void
    {
        $deposito = $this->createDeposito([
            'amount' => 1000.50,
            'deposito_liquido' => 975.00,
            'status' => 'PAID_OUT',
        ]);

        $filters = ['page' => 1, 'limit' => 20];
        $result = $this->service->getDeposits($filters);

        $formatted = $result['data'][0];
        
        $this->assertArrayHasKey('id', $formatted);
        $this->assertArrayHasKey('transacao_id', $formatted);
        $this->assertArrayHasKey('valor_total', $formatted);
        $this->assertArrayHasKey('valor_liquido', $formatted);
        $this->assertArrayHasKey('status_legivel', $formatted);
        $this->assertEquals(1000.50, $formatted['valor_total']);
        $this->assertEquals(975.00, $formatted['valor_liquido']);
    }
}

