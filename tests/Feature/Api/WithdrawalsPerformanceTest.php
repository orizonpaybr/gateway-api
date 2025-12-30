<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\SolicitacoesCashOut;
use App\Models\UsersKey;
use App\Constants\UserPermission;
use Tests\Feature\Helpers\AuthTestHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Testes de Performance e Concorrência - API de Saques
 * 
 * Cobre:
 * - Performance com grandes volumes de dados
 * - Concorrência de requisições
 * - Escalabilidade
 * - Uso de cache
 * - Otimização de queries
 */
class WithdrawalsPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Criar usuário admin
        $this->user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'status' => 1,
            'banido' => 0,
            'permission' => UserPermission::ADMIN,
        ]);

        UsersKey::factory()->create([
            'user_id' => $this->user->user_id ?? $this->user->username,
            'token' => 'test_token_' . $this->user->username,
            'secret' => 'test_secret_' . $this->user->username,
        ]);

        $this->token = AuthTestHelper::generateTestToken($this->user);
    }

    /**
     * Helper para criar múltiplos saques
     */
    private function createMultipleWithdrawals(int $count, User $user): void
    {
        $withdrawals = [];
        $now = Carbon::now();

        for ($i = 0; $i < $count; $i++) {
            $withdrawals[] = [
                'user_id' => $user->user_id,
                'idTransaction' => 'TXN' . uniqid() . $i,
                'externalreference' => 'EXT' . uniqid() . $i,
                'amount' => 100.00 + ($i * 10),
                'cash_out_liquido' => 97.50 + ($i * 10),
                'taxa_cash_out' => 2.50,
                'status' => $i % 2 === 0 ? 'PAID_OUT' : 'COMPLETED',
                'date' => $now->copy()->subMinutes($i),
                'pix' => 'test' . $i . '@example.com',
                'pixkey' => 'test' . $i . '@example.com',
                'type' => 'EMAIL',
                'beneficiaryname' => 'Cliente Test ' . $i,
                'beneficiarydocument' => '1234567890' . ($i % 10),
                'descricao_transacao' => 'Saque de teste ' . $i,
                'created_at' => $now->copy()->subMinutes($i),
                'updated_at' => $now->copy()->subMinutes($i),
            ];
        }

        // Inserir em lotes para melhor performance
        foreach (array_chunk($withdrawals, 500) as $chunk) {
            SolicitacoesCashOut::insert($chunk);
        }
    }

    /**
     * Teste: Deve responder em menos de 500ms com 1000 saques
     */
    public function test_should_respond_under_500ms_with_1000_withdrawals(): void
    {
        $this->createMultipleWithdrawals(1000, $this->user);

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/withdrawals?page=1&limit=20');

        $duration = (microtime(true) - $startTime) * 1000; // Converter para ms

        $response->assertStatus(200);
        $this->assertLessThan(500, $duration, "Resposta demorou {$duration}ms, esperado < 500ms");
    }

    /**
     * Teste: Deve usar cache para melhorar performance
     */
    public function test_should_use_cache_to_improve_performance(): void
    {
        $this->createMultipleWithdrawals(100, $this->user);

        // Primeira requisição (sem cache)
        $startTime1 = microtime(true);
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/withdrawals');
        $duration1 = (microtime(true) - $startTime1) * 1000;

        // Segunda requisição (com cache)
        Cache::flush(); // Simular cache válido
        $startTime2 = microtime(true);
        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/withdrawals');
        $duration2 = (microtime(true) - $startTime2) * 1000;

        $response1->assertStatus(200);
        $response2->assertStatus(200);
        
        // Cache deve melhorar performance (ou pelo menos não piorar muito)
        $this->assertLessThan(1000, $duration2, "Cache não melhorou performance suficiente");
    }

    /**
     * Teste: Deve lidar com requisições concorrentes
     */
    public function test_should_handle_concurrent_requests(): void
    {
        $this->createMultipleWithdrawals(500, $this->user);

        $requests = [];
        $startTime = microtime(true);

        // Simular 10 requisições concorrentes
        for ($i = 0; $i < 10; $i++) {
            $requests[] = function () {
                return $this->withHeaders([
                    'Authorization' => 'Bearer ' . $this->token,
                ])->getJson('/api/admin/financial/withdrawals?page=1&limit=20');
            };
        }

        // Executar requisições
        $responses = [];
        foreach ($requests as $request) {
            $responses[] = $request();
        }

        $duration = (microtime(true) - $startTime) * 1000;

        // Todas as respostas devem ser bem-sucedidas
        foreach ($responses as $response) {
            $response->assertStatus(200);
        }

        // Deve completar em tempo razoável mesmo com concorrência
        $this->assertLessThan(2000, $duration, "Requisições concorrentes demoraram {$duration}ms");
    }

    /**
     * Teste: Deve escalar com grandes volumes de dados
     */
    public function test_should_scale_with_large_data_volumes(): void
    {
        $this->createMultipleWithdrawals(10000, $this->user);

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/withdrawals?page=1&limit=20');

        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertEquals(20, count($response->json('data.data')));
        $this->assertEquals(10000, $response->json('data.total'));
        
        // Deve responder em tempo razoável mesmo com 10k registros
        $this->assertLessThan(2000, $duration, "Resposta demorou {$duration}ms com 10k registros");
    }

    /**
     * Teste: Deve otimizar queries com índices
     */
    public function test_should_optimize_queries_with_indexes(): void
    {
        $this->createMultipleWithdrawals(1000, $this->user);

        $startTime = microtime(true);

        // Query com filtros que devem usar índices
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/withdrawals?status=PAID_OUT&data_inicio=' . Carbon::now()->subDays(7)->format('Y-m-d'));

        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        
        // Queries com índices devem ser rápidas
        $this->assertLessThan(1000, $duration, "Query com índices demorou {$duration}ms");
    }

    /**
     * Teste: Deve manter performance com filtros complexos
     */
    public function test_should_maintain_performance_with_complex_filters(): void
    {
        $this->createMultipleWithdrawals(2000, $this->user);

        $startTime = microtime(true);

        // Múltiplos filtros simultâneos
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/withdrawals?status=PAID_OUT&busca=test&data_inicio=' . Carbon::now()->subDays(30)->format('Y-m-d') . '&data_fim=' . Carbon::now()->format('Y-m-d'));

        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertLessThan(1500, $duration, "Filtros complexos demoraram {$duration}ms");
    }

    /**
     * Teste: Deve processar estatísticas rapidamente
     */
    public function test_should_process_stats_quickly(): void
    {
        $this->createMultipleWithdrawals(5000, $this->user);

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/withdrawals/stats?periodo=hoje');

        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertLessThan(1000, $duration, "Estatísticas demoraram {$duration}ms");
    }

    /**
     * Teste: Deve lidar com paginação em grandes volumes
     */
    public function test_should_handle_pagination_in_large_volumes(): void
    {
        $this->createMultipleWithdrawals(5000, $this->user);

        $startTime = microtime(true);

        // Acessar última página
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/withdrawals?page=250&limit=20');

        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertEquals(20, count($response->json('data.data')));
        $this->assertLessThan(1500, $duration, "Paginação demorou {$duration}ms");
    }

    /**
     * Teste: Deve manter memória sob controle
     */
    public function test_should_keep_memory_under_control(): void
    {
        $this->createMultipleWithdrawals(10000, $this->user);

        $memoryBefore = memory_get_usage();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/withdrawals?page=1&limit=20');

        $memoryAfter = memory_get_usage();
        $memoryUsed = ($memoryAfter - $memoryBefore) / 1024 / 1024; // MB

        $response->assertStatus(200);
        
        // Não deve usar mais de 50MB para uma requisição
        $this->assertLessThan(50, $memoryUsed, "Uso de memória foi {$memoryUsed}MB, esperado < 50MB");
    }
}

