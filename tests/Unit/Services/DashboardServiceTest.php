<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\DashboardService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class DashboardServiceTest extends TestCase
{
    // Removido RefreshDatabase - testes unitários não precisam de banco real

    private DashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DashboardService();
        Cache::flush();
    }

    /**
     * Teste: calculateDateRange retorna intervalo correto para 'hoje'
     */
    public function test_calculate_date_range_hoje(): void
    {
        $result = $this->service->calculateDateRange('hoje');
        
        $this->assertArrayHasKey('inicio', $result);
        $this->assertArrayHasKey('fim', $result);
        $this->assertEquals(now()->startOfDay()->format('Y-m-d'), $result['inicio']->format('Y-m-d'));
        $this->assertEquals(now()->endOfDay()->format('Y-m-d'), $result['fim']->format('Y-m-d'));
    }

    /**
     * Teste: calculateDateRange retorna intervalo correto para '30dias'
     */
    public function test_calculate_date_range_30dias(): void
    {
        $result = $this->service->calculateDateRange('30dias');
        
        $this->assertArrayHasKey('inicio', $result);
        $this->assertArrayHasKey('fim', $result);
        $expectedStart = now()->subDays(29)->startOfDay();
        $this->assertEquals($expectedStart->format('Y-m-d'), $result['inicio']->format('Y-m-d'));
    }

    /**
     * Teste: getDashboardStats retorna estrutura correta
     */
    public function test_get_dashboard_stats_structure(): void
    {
        $user = User::factory()->create(['username' => 'test_user']);
        
        $result = $this->service->getDashboardStats($user->username);
        
        $this->assertArrayHasKey('saldo_disponivel', $result);
        $this->assertArrayHasKey('entradas_mes', $result);
        $this->assertArrayHasKey('saidas_mes', $result);
        $this->assertArrayHasKey('splits_mes', $result);
        $this->assertArrayHasKey('periodo', $result);
        $this->assertIsFloat($result['saldo_disponivel']);
        $this->assertIsFloat($result['entradas_mes']);
    }

    /**
     * Teste: getDashboardStats usa cache
     */
    public function test_get_dashboard_stats_uses_cache(): void
    {
        $user = User::factory()->create(['username' => 'test_user_cache']);
        Cache::flush();
        
        // Primeira chamada
        $result1 = $this->service->getDashboardStats($user->username);
        
        // Segunda chamada deve usar cache
        $result2 = $this->service->getDashboardStats($user->username);
        
        $this->assertEquals($result1, $result2);
    }

    /**
     * Teste: getTransactionSummary retorna estrutura correta
     */
    public function test_get_transaction_summary_structure(): void
    {
        $user = User::factory()->create(['username' => 'test_summary']);
        
        $result = $this->service->getTransactionSummary($user->username, 'hoje');
        
        $this->assertArrayHasKey('periodo', $result);
        $this->assertArrayHasKey('quantidadeTransacoes', $result);
        $this->assertArrayHasKey('tarifaCobrada', $result);
        $this->assertArrayHasKey('qrCodes', $result);
        $this->assertArrayHasKey('indiceConversao', $result);
        $this->assertArrayHasKey('ticketMedio', $result);
        $this->assertArrayHasKey('valorMinMax', $result);
        $this->assertArrayHasKey('infracoes', $result);
        $this->assertArrayHasKey('percentualInfracoes', $result);
    }

    /**
     * Teste: getInteractiveMovement retorna estrutura correta
     */
    public function test_get_interactive_movement_structure(): void
    {
        $user = User::factory()->create(['username' => 'test_interactive']);
        
        $result = $this->service->getInteractiveMovement($user->username, 'hoje');
        
        $this->assertArrayHasKey('periodo', $result);
        $this->assertArrayHasKey('cards', $result);
        $this->assertArrayHasKey('chart', $result);
        $this->assertArrayHasKey('total_depositos', $result['cards']);
        $this->assertArrayHasKey('qtd_depositos', $result['cards']);
        $this->assertIsArray($result['chart']);
    }
}
















