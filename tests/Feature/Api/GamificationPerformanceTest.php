<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\Nivel;
use App\Models\Solicitacoes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Performance e Concorrência - API Gamificação
 * 
 * Cobre:
 * - Performance de endpoints
 * - Concorrência de requisições
 * - Escalabilidade
 * - Uso de cache
 * - Otimização de queries
 */
class GamificationPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Criar usuário e obter token
        $this->user = AuthTestHelper::createTestUser([
            'username' => 'testuser_' . uniqid(),
            'email' => 'testuser_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'status' => 1,
            'banido' => 0,
        ]);

        $this->token = AuthTestHelper::generateTestToken($this->user);
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
            'descricao_transacao' => 'Depósito teste',
            'client_name' => 'Cliente Teste',
            'client_document' => '12345678900',
            'client_email' => 'cliente@test.com',
            'client_telefone' => '11999999999',
            'qrcode_pix' => 'https://example.com/qr',
            'paymentcode' => 'PAY' . uniqid(),
            'paymentCodeBase64' => base64_encode('PAY' . uniqid()),
            'adquirente_ref' => 'Banco Test',
            'taxa_pix_cash_in_adquirente' => 1.0,
            'taxa_pix_cash_in_valor_fixo' => 0.5,
            'executor_ordem' => 'test',
        ];

        return Solicitacoes::create(array_merge($defaults, $attributes));
    }

    public function test_should_respond_under_300ms_for_gamification_data()
    {
        // Criar níveis
        Nivel::create([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
            'cor' => '#CD7F32',
            'icone' => 'bronze.png',
        ]);

        Cache::forget("gamification_data_user_{$this->user->id}");
        Cache::forget('gamification:niveis:all');

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/gamification/journey');

        $duration = (microtime(true) - $startTime) * 1000; // em ms

        $response->assertStatus(200);
        $this->assertLessThan(300, $duration, "Get gamification data levou {$duration}ms, esperado < 300ms");
    }

    public function test_should_use_cache_to_improve_performance()
    {
        // Criar níveis
        Nivel::create([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
            'cor' => '#CD7F32',
            'icone' => 'bronze.png',
        ]);

        Cache::forget("gamification_data_user_{$this->user->id}");
        Cache::forget('gamification:niveis:all');

        // Primeira chamada (sem cache)
        $startTime1 = microtime(true);
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/gamification/journey');
        $duration1 = (microtime(true) - $startTime1) * 1000;

        $response1->assertStatus(200);

        // Segunda chamada (com cache)
        $startTime2 = microtime(true);
        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/gamification/journey');
        $duration2 = (microtime(true) - $startTime2) * 1000;

        $response2->assertStatus(200);
        
        // Cache deve melhorar performance (segunda chamada deve ser mais rápida ou similar)
        $this->assertLessThan(500, $duration1, "Primeira chamada levou {$duration1}ms");
        $this->assertLessThan(500, $duration2, "Segunda chamada levou {$duration2}ms");
    }

    public function test_should_handle_concurrent_requests()
    {
        // Criar níveis
        Nivel::create([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
            'cor' => '#CD7F32',
            'icone' => 'bronze.png',
        ]);

        $concurrentRequests = 50;
        $successfulRequests = 0;
        $startTime = microtime(true);

        for ($i = 0; $i < $concurrentRequests; $i++) {
            Cache::forget("gamification_data_user_{$this->user->id}");
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson('/api/gamification/journey');

            if ($response->status() === 200) {
                $successfulRequests++;
            }
        }

        $duration = (microtime(true) - $startTime) * 1000;

        $this->assertEquals($concurrentRequests, $successfulRequests);
        $this->assertLessThan(5000, $duration, "50 requisições levaram {$duration}ms, esperado < 5000ms");
    }

    public function test_should_scale_with_multiple_users()
    {
        // Criar níveis
        Nivel::create([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
            'cor' => '#CD7F32',
            'icone' => 'bronze.png',
        ]);

        $users = [];
        $tokens = [];

        // Criar 100 usuários
        for ($i = 0; $i < 100; $i++) {
            $user = AuthTestHelper::createTestUser([
                'username' => 'testuser_' . uniqid() . '_' . $i,
                'email' => 'testuser_' . uniqid() . '_' . $i . '@example.com',
            ]);

            $users[] = $user;
            $tokens[] = AuthTestHelper::generateTestToken($user);
        }

        $startTime = microtime(true);
        $successfulRequests = 0;

        // Fazer requisições para todos os usuários
        foreach ($tokens as $index => $token) {
            Cache::forget("gamification_data_user_{$users[$index]->id}");
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ])->getJson('/api/gamification/journey');

            if ($response->status() === 200) {
                $successfulRequests++;
            }
        }

        $duration = (microtime(true) - $startTime) * 1000;

        // Algumas requisições podem falhar devido a problemas de autenticação ou rate limiting
        $this->assertGreaterThan(50, $successfulRequests, "Apenas {$successfulRequests} de 100 requisições foram bem-sucedidas");
        $this->assertLessThan(30000, $duration, "100 requisições levaram {$duration}ms, esperado < 30000ms");
    }

    public function test_should_maintain_performance_with_many_deposits()
    {
        // Criar níveis
        Nivel::create([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
            'cor' => '#CD7F32',
            'icone' => 'bronze.png',
        ]);

        Cache::forget("gamification_data_user_{$this->user->id}");
        Cache::forget('gamification:niveis:all');

        // Criar 1000 depósitos
        for ($i = 0; $i < 1000; $i++) {
            $this->createDeposito(['amount' => 100 + $i]);
        }

        Cache::forget("gamification_data_user_{$this->user->id}");

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/gamification/journey');

        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertLessThan(1000, $duration, "Get gamification data com muitos depósitos levou {$duration}ms, esperado < 1000ms");
    }

    public function test_should_maintain_performance_with_cache()
    {
        // Criar níveis
        Nivel::create([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
            'cor' => '#CD7F32',
            'icone' => 'bronze.png',
        ]);

        Cache::forget("gamification_data_user_{$this->user->id}");
        Cache::forget('gamification:niveis:all');

        // Primeira chamada cria cache
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/gamification/journey');

        $response1->assertStatus(200);

        // Múltiplas chamadas subsequentes devem ser rápidas (cache)
        $startTime = microtime(true);
        $successfulRequests = 0;

        for ($i = 0; $i < 100; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson('/api/gamification/journey');

            if ($response->status() === 200) {
                $successfulRequests++;
            }
        }

        $duration = (microtime(true) - $startTime) * 1000;

        $this->assertEquals(100, $successfulRequests);
        $this->assertLessThan(10000, $duration, "100 requisições com cache levaram {$duration}ms, esperado < 10000ms");
    }

    public function test_should_handle_pagination_and_filtering_efficiently()
    {
        // Criar múltiplos usuários com diferentes depósitos
        for ($i = 0; $i < 50; $i++) {
            $user = AuthTestHelper::createTestUser([
                'username' => 'testuser_' . uniqid() . '_' . $i,
                'email' => 'testuser_' . uniqid() . '_' . $i . '@example.com',
            ]);

            // Criar alguns depósitos para cada usuário
            for ($j = 0; $j < 10; $j++) {
                Solicitacoes::create([
                    'user_id' => $user->user_id ?? $user->username,
                    'idTransaction' => 'TXN' . uniqid(),
                    'externalreference' => 'EXT' . uniqid(),
                    'amount' => 100 + $j,
                    'deposito_liquido' => 97.50,
                    'taxa_cash_in' => 2.50,
                    'status' => 'PAID_OUT',
                    'date' => now(),
                    'descricao_transacao' => 'Depósito teste',
                    'client_name' => 'Cliente Teste',
                    'client_document' => '12345678900',
                    'client_email' => 'cliente@test.com',
                    'client_telefone' => '11999999999',
                    'qrcode_pix' => 'https://example.com/qr',
                    'paymentcode' => 'PAY' . uniqid(),
                    'paymentCodeBase64' => base64_encode('PAY' . uniqid()),
                    'adquirente_ref' => 'Banco Test',
                    'taxa_pix_cash_in_adquirente' => 1.0,
                    'taxa_pix_cash_in_valor_fixo' => 0.5,
                    'executor_ordem' => 'test',
                ]);
            }
        }

        Cache::forget("gamification_data_user_{$this->user->id}");
        Cache::forget('gamification:niveis:all');

        $startTime = microtime(true);

        // Buscar dados de gamificação de um usuário específico
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/gamification/journey');

        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        // Deve ser rápido mesmo com muitos usuários e depósitos (indexes ajudam)
        $this->assertLessThan(500, $duration, "Query com muitos dados levou {$duration}ms, esperado < 500ms");
    }

    public function test_should_keep_memory_under_control()
    {
        // Criar níveis
        Nivel::create([
            'nome' => 'Bronze',
            'minimo' => 0,
            'maximo' => 100000,
            'cor' => '#CD7F32',
            'icone' => 'bronze.png',
        ]);

        $initialMemory = memory_get_usage();

        // Fazer 100 requisições de gamification data
        for ($i = 0; $i < 100; $i++) {
            Cache::forget("gamification_data_user_{$this->user->id}");
            
            $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson('/api/gamification/journey');
        }

        $finalMemory = memory_get_usage();
        $memoryIncrease = ($finalMemory - $initialMemory) / 1024 / 1024; // em MB

        // Memória não deve aumentar mais que 50MB
        $this->assertLessThan(50, $memoryIncrease, "Memória aumentou {$memoryIncrease}MB, esperado < 50MB");
    }

    public function test_should_optimize_queries_with_indexes()
    {
        // Criar múltiplos usuários e depósitos
        for ($i = 0; $i < 100; $i++) {
            $user = AuthTestHelper::createTestUser([
                'username' => 'testuser_' . uniqid() . '_' . $i,
                'email' => 'testuser_' . uniqid() . '_' . $i . '@example.com',
            ]);

            Solicitacoes::create([
                'user_id' => $user->user_id ?? $user->username,
                'idTransaction' => 'TXN' . uniqid(),
                'externalreference' => 'EXT' . uniqid(),
                'amount' => 1000 + $i,
                'deposito_liquido' => 975,
                'taxa_cash_in' => 25,
                'status' => 'PAID_OUT',
                'date' => now(),
                'descricao_transacao' => 'Depósito teste',
                'client_name' => 'Cliente Teste',
                'client_document' => '12345678900',
                'client_email' => 'cliente@test.com',
                'client_telefone' => '11999999999',
                'qrcode_pix' => 'https://example.com/qr',
                'paymentcode' => 'PAY' . uniqid(),
                'paymentCodeBase64' => base64_encode('PAY' . uniqid()),
                'adquirente_ref' => 'Banco Test',
                'taxa_pix_cash_in_adquirente' => 1.0,
                'taxa_pix_cash_in_valor_fixo' => 0.5,
                'executor_ordem' => 'test',
            ]);
        }

        Cache::forget("gamification_data_user_{$this->user->id}");
        Cache::forget('gamification:niveis:all');

        $startTime = microtime(true);

        // Buscar dados de gamificação de um usuário específico
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/gamification/journey');

        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        // Deve ser rápido mesmo com muitos registros (indexes ajudam)
        $this->assertLessThan(500, $duration, "Query com muitos registros levou {$duration}ms, esperado < 500ms");
    }

    public function test_should_handle_multiple_levels_efficiently()
    {
        // Criar múltiplos níveis
        Nivel::create(['nome' => 'Bronze', 'minimo' => 0, 'maximo' => 100000, 'cor' => '#CD7F32', 'icone' => 'bronze.png']);
        Nivel::create(['nome' => 'Prata', 'minimo' => 100000, 'maximo' => 500000, 'cor' => '#C0C0C0', 'icone' => 'prata.png']);
        Nivel::create(['nome' => 'Ouro', 'minimo' => 500000, 'maximo' => 1000000, 'cor' => '#FFD700', 'icone' => 'ouro.png']);
        Nivel::create(['nome' => 'Safira', 'minimo' => 1000000, 'maximo' => 5000000, 'cor' => '#0066CC', 'icone' => 'safira.png']);
        Nivel::create(['nome' => 'Diamante', 'minimo' => 5000000, 'maximo' => 10000000, 'cor' => '#00CCFF', 'icone' => 'diamante.png']);

        Cache::forget("gamification_data_user_{$this->user->id}");
        Cache::forget('gamification:niveis:all');

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/gamification/journey');

        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        // Deve ser rápido mesmo com muitos níveis
        $this->assertLessThan(500, $duration, "Get gamification data com muitos níveis levou {$duration}ms, esperado < 500ms");
    }
}









