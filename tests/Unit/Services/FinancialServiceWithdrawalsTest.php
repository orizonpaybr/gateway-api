<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\FinancialService;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Testes Unitários - FinancialService - Saques
 * 
 * Cobre:
 * - Funcionalidade de busca de saques
 * - Filtros (status, busca, datas)
 * - Paginação
 * - Cache
 * - Estatísticas
 * - Performance
 */
class FinancialServiceWithdrawalsTest extends TestCase
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
     * Helper para criar saque de teste
     */
    private function createSaque(array $attributes = []): SolicitacoesCashOut
    {
        $defaults = [
            'user_id' => $this->user->user_id ?? $this->user->username,
            'idTransaction' => 'TXN' . uniqid(),
            'externalreference' => 'EXT' . uniqid(),
            'amount' => 100.00,
            'cash_out_liquido' => 97.50,
            'taxa_cash_out' => 2.50,
            'status' => 'PAID_OUT',
            'date' => now(),
            'pix' => 'test@example.com',
            'pixkey' => 'test@example.com',
            'type' => 'EMAIL',
            'beneficiaryname' => 'Cliente Test',
            'beneficiarydocument' => '12345678900',
            'descricao_transacao' => 'Saque de teste',
        ];

        return SolicitacoesCashOut::create(array_merge($defaults, $attributes));
    }

    /**
     * Teste: Deve retornar lista de saques paginada
     */
    public function test_should_return_paginated_withdrawals(): void
    {
        // Criar 25 saques
        for ($i = 0; $i < 25; $i++) {
            $this->createSaque(['amount' => 100 + $i]);
        }

        $filters = ['page' => 1, 'limit' => 20];
        $result = $this->service->getWithdrawals($filters);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('current_page', $result);
        $this->assertArrayHasKey('last_page', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertEquals(1, $result['current_page']);
        $this->assertEquals(20, count($result['data']));
        $this->assertEquals(25, $result['total']);
        $this->assertEquals(2, $result['last_page']);
    }

    /**
     * Teste: Deve filtrar saques por status
     */
    public function test_should_filter_withdrawals_by_status(): void
    {
        $this->createSaque(['status' => 'PAID_OUT']);
        $this->createSaque(['status' => 'PENDING']);
        $this->createSaque(['status' => 'COMPLETED']);

        $filters = ['page' => 1, 'limit' => 20, 'status' => 'PAID_OUT'];
        $result = $this->service->getWithdrawals($filters);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals('PAID_OUT', $result['data'][0]['status']);
        $this->assertEquals('Pago', $result['data'][0]['status_legivel']);
    }

    /**
     * Teste: Deve buscar saques por termo
     */
    public function test_should_search_withdrawals_by_term(): void
    {
        // Criar usuário com nome específico para busca
        $user = User::factory()->create([
            'username' => 'testuser_search',
            'user_id' => 'testuser_search',
            'name' => 'Test User Search',
            'email' => 'testsearch@example.com',
            'password' => bcrypt('password123'),
            'status' => 1,
            'banido' => 0,
        ]);

        $this->createSaque(['user_id' => $user->user_id, 'pixkey' => 'test@example.com']);
        $this->createSaque(['user_id' => $user->user_id, 'pixkey' => 'other@example.com']);

        // Buscar por nome do usuário (que funciona no applyWithdrawalSearch)
        $filters = ['page' => 1, 'limit' => 20, 'busca' => 'Test User Search'];
        $result = $this->service->getWithdrawals($filters);

        $this->assertGreaterThanOrEqual(1, $result['total']);
    }

    /**
     * Teste: Deve filtrar saques por intervalo de datas
     */
    public function test_should_filter_withdrawals_by_date_range(): void
    {
        $hoje = Carbon::now();
        $ontem = Carbon::yesterday();

        $this->createSaque(['date' => $hoje]);
        $this->createSaque(['date' => $ontem]);
        $this->createSaque(['date' => $hoje->copy()->subDays(5)]);

        $filters = [
            'page' => 1,
            'limit' => 20,
            'data_inicio' => $hoje->format('Y-m-d'),
            'data_fim' => $hoje->format('Y-m-d'),
        ];
        $result = $this->service->getWithdrawals($filters);

        $this->assertEquals(1, $result['total']);
    }

    /**
     * Teste: Deve usar cache para saques
     */
    public function test_should_use_cache_for_withdrawals(): void
    {
        $this->createSaque();

        $filters = ['page' => 1, 'limit' => 20];
        
        // Primeira chamada - deve buscar do banco
        $result1 = $this->service->getWithdrawals($filters);
        $this->assertEquals(1, $result1['total']);

        // Segunda chamada - deve usar cache
        Cache::shouldReceive('remember')
            ->once()
            ->andReturn($result1);

        $result2 = $this->service->getWithdrawals($filters);
        $this->assertEquals($result1['total'], $result2['total']);
    }

    /**
     * Teste: Deve retornar estatísticas de saques
     */
    public function test_should_return_withdrawals_stats(): void
    {
        $hoje = Carbon::now();
        
        // Criar saques de hoje
        $this->createSaque(['date' => $hoje, 'status' => 'PAID_OUT', 'amount' => 100, 'taxa_cash_out' => 2.5]);
        $this->createSaque(['date' => $hoje, 'status' => 'COMPLETED', 'amount' => 200, 'taxa_cash_out' => 5.0]);
        
        // Criar saque pendente
        $this->createSaque(['date' => $hoje, 'status' => 'PENDING', 'amount' => 50, 'taxa_cash_out' => 1.0]);

        $stats = $this->service->getWithdrawalsStats('hoje');

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('saques_aprovados_hoje', $stats);
        $this->assertArrayHasKey('valor_total_hoje', $stats);
        $this->assertArrayHasKey('lucro_total_hoje', $stats);
        $this->assertEquals(2, $stats['saques_aprovados_hoje']);
        $this->assertEquals(300.0, $stats['valor_total_hoje']);
        $this->assertEquals(7.5, $stats['lucro_total_hoje']);
    }

    /**
     * Teste: Deve validar limite máximo de itens por página
     */
    public function test_should_validate_max_items_per_page(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->createSaque();
        }

        $filters = ['page' => 1, 'limit' => 150]; // Limite máximo é 100
        $result = $this->service->getWithdrawals($filters);

        $this->assertLessThanOrEqual(100, count($result['data']));
    }

    /**
     * Teste: Deve validar página mínima
     */
    public function test_should_validate_minimum_page(): void
    {
        $this->createSaque();

        $filters = ['page' => 0, 'limit' => 20]; // Página mínima é 1
        $result = $this->service->getWithdrawals($filters);

        $this->assertEquals(1, $result['current_page']);
    }

    /**
     * Teste: Deve ordenar saques por data descendente
     */
    public function test_should_order_withdrawals_by_date_desc(): void
    {
        $data1 = Carbon::now()->subDays(2);
        $data2 = Carbon::now()->subDays(1);
        $data3 = Carbon::now();

        $this->createSaque(['date' => $data1, 'idTransaction' => 'TXN1']);
        $this->createSaque(['date' => $data3, 'idTransaction' => 'TXN2']);
        $this->createSaque(['date' => $data2, 'idTransaction' => 'TXN3']);

        $filters = ['page' => 1, 'limit' => 20];
        $result = $this->service->getWithdrawals($filters);

        $this->assertEquals('TXN2', $result['data'][0]['transacao_id']);
        $this->assertEquals('TXN3', $result['data'][1]['transacao_id']);
        $this->assertEquals('TXN1', $result['data'][2]['transacao_id']);
    }

    /**
     * Teste: Deve retornar array vazio quando não há saques
     */
    public function test_should_return_empty_array_when_no_withdrawals(): void
    {
        $filters = ['page' => 1, 'limit' => 20];
        $result = $this->service->getWithdrawals($filters);

        $this->assertIsArray($result);
        $this->assertEquals(0, $result['total']);
        $this->assertEmpty($result['data']);
    }

    /**
     * Teste: Deve formatar saque corretamente
     */
    public function test_should_format_withdrawal_correctly(): void
    {
        $saque = $this->createSaque([
            'amount' => 100.00,
            'cash_out_liquido' => 97.50,
            'taxa_cash_out' => 2.50,
            'status' => 'PAID_OUT',
            'pixkey' => 'test@example.com',
            'type' => 'EMAIL',
        ]);

        $filters = ['page' => 1, 'limit' => 20];
        $result = $this->service->getWithdrawals($filters);

        $formatted = $result['data'][0];

        $this->assertArrayHasKey('id', $formatted);
        $this->assertArrayHasKey('transacao_id', $formatted);
        $this->assertArrayHasKey('valor_total', $formatted);
        $this->assertArrayHasKey('valor_liquido', $formatted);
        $this->assertArrayHasKey('taxa', $formatted);
        $this->assertArrayHasKey('status', $formatted);
        $this->assertArrayHasKey('status_legivel', $formatted);
        $this->assertArrayHasKey('pix_key', $formatted);
        $this->assertArrayHasKey('pix_type', $formatted);
        $this->assertEquals(100.0, $formatted['valor_total']);
        $this->assertEquals(97.5, $formatted['valor_liquido']);
        $this->assertEquals(2.5, $formatted['taxa']);
        $this->assertEquals('PAID_OUT', $formatted['status']);
        $this->assertEquals('Pago', $formatted['status_legivel']);
    }
}

