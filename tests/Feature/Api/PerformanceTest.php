<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Testes de Performance e Escalabilidade
 * 
 * Valida que o sistema suporta carga alta e mantém performance
 */
class PerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        
        $this->user = User::factory()->create([
            'username' => 'perf_test',
            'email' => 'perf@test.com',
            'password' => bcrypt('password123'),
            'status' => 1, // Ativo
            'banido' => 0, // Não banido
            'user_id' => 'perf_test', // Garantir que user_id corresponde ao username
        ]);
        
        // Criar UsersKey (necessário para login) - usar user_id do usuário
        \App\Models\UsersKey::factory()->create([
            'user_id' => $this->user->user_id ?? $this->user->username,
            'token' => 'test_token_' . $this->user->username,
            'secret' => 'test_secret_' . $this->user->username,
        ]);
        
        // Gerar token JWT via login
        $response = $this->postJson('/api/auth/login', [
            'username' => $this->user->username,
            'password' => 'password123',
        ]);
        
        $token = $response->json('data.token');
        
        // Se login falhar, usar actingAs como fallback
        if (!$token || $response->status() !== 200) {
            $this->actingAs($this->user);
            $this->token = 'acting_as_token';
        } else {
            $this->token = $token;
        }
    }

    /**
     * Helper para obter headers de autenticação
     */
    private function getAuthHeaders(): array
    {
        return $this->token === 'acting_as_token' 
            ? [] 
            : ['Authorization' => 'Bearer ' . $this->token];
    }

    /**
     * Helper para criar transações de teste
     */
    private function createSolicitacoes(int $count, array $attributes = []): void
    {
        $userId = $attributes['user_id'] ?? $this->user->user_id ?? $this->user->username;
        $data = [];
        
        for ($i = 0; $i < $count; $i++) {
            $uniqueId = uniqid() . '_' . $i;
            $data[] = array_merge([
                'user_id' => $userId,
                'amount' => rand(10, 1000),
                'status' => 'PAID_OUT',
                'date' => $attributes['date'] ?? now()->subDays(rand(0, 30)),
                'taxa_cash_in' => rand(1, 10),
                'externalreference' => 'TEST_' . $uniqueId,
                'client_name' => 'Test Client ' . $i,
                'client_document' => '1234567890' . $i,
                'client_email' => 'test' . $i . '@test.com',
                'idTransaction' => 'TXN_' . $uniqueId,
                'deposito_liquido' => rand(10, 1000) - rand(1, 10),
                'qrcode_pix' => 'https://example.com/qr/' . $uniqueId,
                'paymentcode' => 'PAY_' . $uniqueId,
                'paymentCodeBase64' => base64_encode('PAY_' . $uniqueId),
                'adquirente_ref' => 'ADQ_' . $uniqueId,
                'taxa_pix_cash_in_adquirente' => rand(1, 5),
                'taxa_pix_cash_in_valor_fixo' => rand(1, 3),
                'client_telefone' => '1199999999' . ($i % 10),
                'executor_ordem' => 'EXEC_' . $uniqueId,
                'descricao_transacao' => 'Test Transaction ' . $i,
                'created_at' => now(),
                'updated_at' => now(),
            ], $attributes);
        }
        
        DB::table('solicitacoes')->insert($data);
    }

    /**
     * Helper para criar saques de teste
     */
    private function createSolicitacoesCashOut(int $count, array $attributes = []): void
    {
        $userId = $attributes['user_id'] ?? $this->user->user_id ?? $this->user->username;
        $data = [];
        
        for ($i = 0; $i < $count; $i++) {
            $uniqueId = uniqid() . '_' . $i;
            $data[] = array_merge([
                'user_id' => $userId,
                'amount' => rand(10, 1000),
                'status' => 'PAID_OUT',
                'date' => $attributes['date'] ?? now()->subDays(rand(0, 30)),
                'taxa_cash_out' => rand(1, 10),
                'externalreference' => 'TEST_CASHOUT_' . $uniqueId,
                'beneficiaryname' => 'Beneficiary ' . $i,
                'beneficiarydocument' => '9876543210' . $i,
                'pix' => 'pix@test.com',
                'pixkey' => 'test_key_' . $uniqueId,
                'type' => 'PIX',
                'idTransaction' => 'CASHOUT_TXN_' . $uniqueId,
                'cash_out_liquido' => rand(10, 1000) - rand(1, 10),
                'created_at' => now(),
                'updated_at' => now(),
            ], $attributes);
        }
        
        DB::table('solicitacoes_cash_out')->insert($data);
    }

    /**
     * Teste: Performance de endpoint de dashboard com cache
     */
    public function test_dashboard_stats_performance_with_cache(): void
    {
        // Criar transações de teste
        $this->createSolicitacoes(100, [
            'user_id' => $this->user->user_id ?? $this->user->username,
            'date' => now(),
        ]);

        Cache::flush();

        // Primeira requisição (sem cache)
        $start1 = microtime(true);
        $response1 = $this->withHeaders($this->getAuthHeaders())->getJson('/api/dashboard/stats-optimized');
        $time1 = (microtime(true) - $start1) * 1000; // em ms

        // Segunda requisição (com cache)
        $start2 = microtime(true);
        $response2 = $this->withHeaders($this->getAuthHeaders())->getJson('/api/dashboard/stats-optimized');
        $time2 = (microtime(true) - $start2) * 1000; // em ms

        $response1->assertStatus(200);
        $response2->assertStatus(200);

        // Cache deve ser pelo menos 2x mais rápido
        $this->assertLessThan($time1, $time2 * 2, 'Cache não está melhorando performance');
        
        // Tempo de resposta deve ser < 500ms mesmo sem cache
        $this->assertLessThan(500, $time1, 'Primeira requisição muito lenta');
    }

    /**
     * Teste: Performance com múltiplas requisições simultâneas
     */
    public function test_concurrent_requests_performance(): void
    {
        // Criar dados de teste
        $this->createSolicitacoes(50, [
            'user_id' => $this->user->user_id ?? $this->user->username,
            'date' => now(),
        ]);

        Cache::flush();

        $startTime = microtime(true);
        $concurrentRequests = 10;
        $responses = [];

        // Simular requisições simultâneas
        for ($i = 0; $i < $concurrentRequests; $i++) {
            $responses[] = $this->withHeaders($this->getAuthHeaders())->getJson('/api/dashboard/stats-optimized');
        }

        $totalTime = (microtime(true) - $startTime) * 1000; // em ms

        // Todas devem retornar 200
        foreach ($responses as $response) {
            $response->assertStatus(200);
        }

        // 10 requisições devem completar em < 2 segundos
        $this->assertLessThan(2000, $totalTime, 'Requisições simultâneas muito lentas');
        
        // Tempo médio por requisição deve ser < 200ms
        $avgTime = $totalTime / $concurrentRequests;
        $this->assertLessThan(200, $avgTime, 'Tempo médio muito alto');
    }

    /**
     * Teste: Performance de query com muitos registros
     */
    public function test_large_dataset_performance(): void
    {
        // Criar muitos registros (simulando produção)
        $this->createSolicitacoes(1000, [
            'user_id' => $this->user->user_id ?? $this->user->username,
        ]);
        
        $this->createSolicitacoesCashOut(500, [
            'user_id' => $this->user->user_id ?? $this->user->username,
        ]);

        Cache::flush();

        $startTime = microtime(true);
        
        $response = $this->withHeaders($this->getAuthHeaders())->getJson('/api/dashboard/transaction-summary-optimized?periodo=30dias');

        $duration = (microtime(true) - $startTime) * 1000; // em ms

        $response->assertStatus(200);
        
        // Mesmo com muitos registros, deve responder em < 1000ms
        $this->assertLessThan(1000, $duration, 'Query muito lenta com muitos registros');
    }

    /**
     * Teste: Escalabilidade - múltiplos usuários simultâneos
     */
    public function test_scalability_multiple_users(): void
    {
        // Criar múltiplos usuários
        $users = User::factory()->count(5)->create();
        $tokens = [];

        foreach ($users as $user) {
            // Garantir que user_id corresponde ao username
            if (!$user->user_id) {
                $user->user_id = $user->username;
                $user->save();
            }
            
            // Criar UsersKey para cada usuário
            \App\Models\UsersKey::factory()->create([
                'user_id' => $user->user_id ?? $user->username,
                'token' => 'test_token_' . $user->username,
                'secret' => 'test_secret_' . $user->username,
            ]);
            
            // Criar transações para cada usuário
            $this->createSolicitacoes(20, [
                'user_id' => $user->user_id ?? $user->username,
            ]);

            // Login e obter token
            $loginResponse = $this->postJson('/api/auth/login', [
                'username' => $user->username,
                'password' => 'password',
            ]);
            
            $token = $loginResponse->json('data.token');
            if ($token) {
                $tokens[] = $token;
            } else {
                // Fallback: usar actingAs
                $this->actingAs($user);
                $tokens[] = 'acting_as_token';
            }
        }

        Cache::flush();

        $startTime = microtime(true);
        $responses = [];

        // Requisições simultâneas de diferentes usuários
        foreach ($tokens as $index => $token) {
            $headers = $token === 'acting_as_token' 
                ? [] 
                : ['Authorization' => 'Bearer ' . $token];
            $responses[] = $this->withHeaders($headers)->getJson('/api/dashboard/stats-optimized');
        }

        $totalTime = (microtime(true) - $startTime) * 1000;

        // Todas devem retornar 200
        foreach ($responses as $response) {
            $response->assertStatus(200);
        }

        // 5 usuários simultâneos devem completar em < 1 segundo
        $this->assertLessThan(1000, $totalTime, 'Sistema não escala bem com múltiplos usuários');
    }

    /**
     * Teste: Cache hit rate deve ser alto após warmup
     */
    public function test_cache_hit_rate(): void
    {
        $this->createSolicitacoes(50, [
            'user_id' => $this->user->user_id ?? $this->user->username,
        ]);

        Cache::flush();

        // Primeira requisição (cache miss)
        $this->withHeaders($this->getAuthHeaders())->getJson('/api/dashboard/stats-optimized');

        // Múltiplas requisições subsequentes (cache hit)
        $cacheHits = 0;
        $totalRequests = 10;

        for ($i = 0; $i < $totalRequests; $i++) {
            $start = microtime(true);
            $response = $this->withHeaders($this->getAuthHeaders())->getJson('/api/dashboard/stats-optimized');
            
            $duration = (microtime(true) - $start) * 1000;
            
            // Se resposta foi muito rápida (< 50ms), provavelmente veio do cache
            if ($duration < 50 && $response->status() === 200) {
                $cacheHits++;
            }
        }

        $hitRate = ($cacheHits / $totalRequests) * 100;
        
        // Cache hit rate deve ser > 80%
        $this->assertGreaterThan(80, $hitRate, 'Cache hit rate muito baixo');
    }

    /**
     * Teste: Performance de query agregada vs múltiplas queries
     */
    public function test_aggregated_query_performance(): void
    {
        $this->createSolicitacoes(200, [
            'user_id' => $this->user->user_id ?? $this->user->username,
        ]);

        Cache::flush();

        // Testar endpoint otimizado (query agregada)
        $startOptimized = microtime(true);
        $responseOptimized = $this->withHeaders($this->getAuthHeaders())->getJson('/api/dashboard/transaction-summary-optimized?periodo=30dias');
        $timeOptimized = (microtime(true) - $startOptimized) * 1000;

        $responseOptimized->assertStatus(200);
        
        // Query agregada deve ser < 500ms mesmo com muitos registros
        $this->assertLessThan(500, $timeOptimized, 'Query agregada muito lenta');
    }

    /**
     * Teste: Throughput - múltiplas requisições por segundo
     */
    public function test_throughput_requests_per_second(): void
    {
        $this->createSolicitacoes(100, [
            'user_id' => $this->user->user_id ?? $this->user->username,
        ]);

        Cache::flush();

        $startTime = microtime(true);
        $requestCount = 20;
        $successful = 0;

        for ($i = 0; $i < $requestCount; $i++) {
            $response = $this->withHeaders($this->getAuthHeaders())->getJson('/api/dashboard/stats-optimized');
            
            if ($response->status() === 200) {
                $successful++;
            }
        }

        $duration = microtime(true) - $startTime; // em segundos
        $throughput = $requestCount / $duration; // requisições por segundo

        // Deve suportar pelo menos 10 req/s
        $this->assertGreaterThan(10, $throughput, 'Throughput muito baixo');
        $this->assertEquals($requestCount, $successful, 'Nem todas as requisições foram bem-sucedidas');
    }
}
















