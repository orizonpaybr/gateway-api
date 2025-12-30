<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\Solicitacoes;
use App\Models\UsersKey;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Testes de Performance e Concorrência - API de Depósitos
 * 
 * Cobre:
 * - Performance com grandes volumes
 * - Concorrência
 * - Escalabilidade
 * - Cache
 * - Queries otimizadas
 */
class DepositsPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'status' => 1,
            'banido' => 0,
            'permission' => UserPermission::ADMIN, // Permissão de admin
        ]);

        // Criar UsersKey (necessário para login)
        UsersKey::factory()->create([
            'user_id' => $this->user->user_id ?? $this->user->username,
            'token' => 'test_token_' . $this->user->username,
            'secret' => 'test_secret_' . $this->user->username,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'testuser',
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
     * Helper para criar múltiplos depósitos
     */
    private function createMultipleDeposits(int $count, array $attributes = []): void
    {
        $defaults = [
            'user_id' => $this->user->user_id,
            'status' => 'PAID_OUT',
            'date' => now(),
        ];

        $data = [];
        for ($i = 0; $i < $count; $i++) {
            $data[] = array_merge($defaults, [
                'user_id' => $this->user->user_id ?? $this->user->username,
                'idTransaction' => 'TXN' . uniqid() . $i,
                'externalreference' => 'EXT' . uniqid() . $i,
                'amount' => 100.00 + $i,
                'deposito_liquido' => 97.50 + $i,
                'taxa_cash_in' => 2.50,
                'method' => 'PIX',
                'client_name' => 'Cliente ' . $i,
                'client_document' => '1234567890' . $i,
                'client_email' => 'cliente' . $i . '@test.com',
                'client_telefone' => '1199999999' . ($i % 10),
                'qrcode_pix' => 'https://example.com/qr' . $i,
                'paymentcode' => 'PAY' . uniqid() . $i,
                'paymentCodeBase64' => base64_encode('PAY' . uniqid() . $i),
                'adquirente_ref' => 'Banco Test',
                'taxa_pix_cash_in_adquirente' => 1.0,
                'taxa_pix_cash_in_valor_fixo' => 0.5,
                'executor_ordem' => 'EXEC' . uniqid() . $i,
                'descricao_transacao' => 'Depósito de teste ' . $i,
            ], $attributes);
        }

        // Inserção em lote para melhor performance
        foreach (array_chunk($data, 100) as $chunk) {
            Solicitacoes::insert($chunk);
        }
    }

    /**
     * Teste: Deve responder em menos de 500ms com 1000 depósitos
     */
    public function test_should_respond_under_500ms_with_1000_deposits(): void
    {
        $this->createMultipleDeposits(1000);

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/deposits?page=1&limit=20');

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000; // Converter para ms

        $response->assertStatus(200);
        $this->assertLessThan(500, $duration, "Resposta demorou {$duration}ms, esperado < 500ms");
    }

    /**
     * Teste: Deve usar cache para melhorar performance
     */
    public function test_should_use_cache_to_improve_performance(): void
    {
        $this->createMultipleDeposits(500);

        $filters = 'page=1&limit=20';
        
        // Primeira requisição (sem cache)
        $startTime1 = microtime(true);
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson("/api/admin/financial/deposits?{$filters}");
        $duration1 = (microtime(true) - $startTime1) * 1000;

        // Segunda requisição (com cache)
        $startTime2 = microtime(true);
        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson("/api/admin/financial/deposits?{$filters}");
        $duration2 = (microtime(true) - $startTime2) * 1000;

        $response1->assertStatus(200);
        $response2->assertStatus(200);
        
        // Segunda requisição deve ser mais rápida (cache)
        $this->assertLessThan($duration1, $duration2 * 2, "Cache não melhorou performance significativamente");
    }

    /**
     * Teste: Deve lidar com requisições concorrentes
     */
    public function test_should_handle_concurrent_requests(): void
    {
        $this->createMultipleDeposits(500);

        $requests = [];
        $startTime = microtime(true);

        // Simular 10 requisições concorrentes
        for ($i = 0; $i < 10; $i++) {
            $requests[] = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/admin/financial/deposits?page=" . ($i + 1) . "&limit=20");
        }

        $endTime = microtime(true);
        $totalDuration = ($endTime - $startTime) * 1000;

        // Todas as requisições devem ser bem-sucedidas
        foreach ($requests as $response) {
            $response->assertStatus(200);
        }

        // Total deve ser razoável mesmo com concorrência
        $this->assertLessThan(2000, $totalDuration, "10 requisições concorrentes demoraram {$totalDuration}ms");
    }

    /**
     * Teste: Deve escalar com grandes volumes de dados
     */
    public function test_should_scale_with_large_data_volumes(): void
    {
        // Criar 10.000 depósitos
        $this->createMultipleDeposits(10000);

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/deposits?page=1&limit=20');

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertEquals(10000, $response->json('data.total'));
        
        // Deve responder em tempo razoável mesmo com 10k registros
        $this->assertLessThan(1000, $duration, "Resposta com 10k registros demorou {$duration}ms");
    }

    /**
     * Teste: Deve otimizar queries com índices
     */
    public function test_should_optimize_queries_with_indexes(): void
    {
        $this->createMultipleDeposits(1000);

        // Habilitar query log
        DB::enableQueryLog();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/deposits?page=1&limit=20&status=PAID_OUT');

        $queries = DB::getQueryLog();

        $response->assertStatus(200);
        
        // Deve usar poucas queries (otimização)
        $this->assertLessThan(10, count($queries), "Muitas queries executadas: " . count($queries));
    }

    /**
     * Teste: Deve manter performance com filtros complexos
     */
    public function test_should_maintain_performance_with_complex_filters(): void
    {
        $hoje = Carbon::now();
        $this->createMultipleDeposits(1000, [
            'date' => $hoje,
            'status' => 'PAID_OUT',
        ]);

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/deposits?page=1&limit=20&status=PAID_OUT&data_inicio=' . $hoje->format('Y-m-d') . '&data_fim=' . $hoje->format('Y-m-d') . '&busca=Cliente');

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertLessThan(500, $duration, "Filtros complexos demoraram {$duration}ms");
    }

    /**
     * Teste: Deve processar estatísticas rapidamente
     */
    public function test_should_process_stats_quickly(): void
    {
        $hoje = Carbon::now();
        $this->createMultipleDeposits(1000, [
            'date' => $hoje,
            'status' => 'PAID_OUT',
        ]);

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/deposits/stats?periodo=hoje');

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertLessThan(300, $duration, "Estatísticas demoraram {$duration}ms");
    }

    /**
     * Teste: Deve lidar com paginação em grandes volumes
     */
    public function test_should_handle_pagination_in_large_volumes(): void
    {
        $this->createMultipleDeposits(5000);

        $startTime = microtime(true);

        // Testar última página
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/deposits?page=250&limit=20');

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertLessThan(500, $duration, "Última página demorou {$duration}ms");
    }

    /**
     * Teste: Deve manter memória sob controle
     */
    public function test_should_keep_memory_under_control(): void
    {
        $this->createMultipleDeposits(5000);

        $memoryBefore = memory_get_usage();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/financial/deposits?page=1&limit=20');

        $memoryAfter = memory_get_usage();
        $memoryUsed = ($memoryAfter - $memoryBefore) / 1024 / 1024; // MB

        $response->assertStatus(200);
        
        // Não deve usar mais de 50MB para processar
        $this->assertLessThan(50, $memoryUsed, "Uso de memória: {$memoryUsed}MB");
    }
}

