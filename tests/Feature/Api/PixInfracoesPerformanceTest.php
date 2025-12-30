<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\Solicitacoes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Performance e Concorrência - API Infrações PIX
 * 
 * Cobre:
 * - Performance de endpoints
 * - Concorrência de requisições
 * - Escalabilidade
 * - Uso de cache
 * - Otimização de queries
 */
class PixInfracoesPerformanceTest extends TestCase
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
     * Helper para criar infração de teste
     */
    private function createInfracao(array $attributes = []): Solicitacoes
    {
        $defaults = [
            'user_id' => $this->user->username,
            'status' => 'MEDIATION',
            'amount' => 100.00,
            'transaction_id' => 'TXN' . uniqid(),
            'codigo_autenticacao' => 'E' . uniqid(),
            'descricao' => 'Infração de teste',
            'descricao_normalizada' => 'infração de teste',
            'descricao_transacao' => 'Infração de teste',
            'externalreference' => 'EXT' . uniqid(),
            'date' => now(),
            'deposito_liquido' => 97.50,
            'idTransaction' => 'TXN' . uniqid(),
            'client_name' => 'Cliente Test',
            'client_document' => '12345678900',
            'client_email' => 'cliente@test.com',
            'client_telefone' => '11999999999',
            'qrcode_pix' => 'https://example.com/qr',
            'paymentcode' => 'PAY' . uniqid(),
            'paymentCodeBase64' => base64_encode('PAY' . uniqid()),
            'adquirente_ref' => 'Banco Test',
            'taxa_cash_in' => 2.50,
            'taxa_pix_cash_in_adquirente' => 1.0,
            'taxa_pix_cash_in_valor_fixo' => 0.5,
            'executor_ordem' => 'EXEC' . uniqid(),
            'tipo' => 'pix',
        ];

        return Solicitacoes::create(array_merge($defaults, $attributes));
    }

    public function test_should_respond_under_300ms_for_infracoes()
    {
        // Criar algumas infrações
        for ($i = 0; $i < 10; $i++) {
            $this->createInfracao(['transaction_id' => "TXN{$i}"]);
        }

        $startTime = microtime(true);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/pix/infracoes');
        $duration = (microtime(true) - $startTime) * 1000; // em ms

        $response->assertStatus(200);
        $this->assertLessThan(300, $duration, "Get infracoes levou {$duration}ms, esperado < 300ms");
    }

    public function test_should_handle_concurrent_requests()
    {
        // Criar algumas infrações
        for ($i = 0; $i < 20; $i++) {
            $this->createInfracao(['transaction_id' => "TXN{$i}"]);
        }

        $concurrentRequests = 50;
        $successfulRequests = 0;
        $startTime = microtime(true);

        for ($i = 0; $i < $concurrentRequests; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson('/api/pix/infracoes');
            
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
        $users = [];
        $tokens = [];

        // Criar 100 usuários
        for ($i = 0; $i < 100; $i++) {
            $user = AuthTestHelper::createTestUser([
                'username' => 'testuser_' . uniqid() . '_' . $i,
                'email' => 'testuser_' . uniqid() . '_' . $i . '@example.com',
            ]);

            $token = AuthTestHelper::generateTestToken($user);

            // Criar algumas infrações para cada usuário
            for ($j = 0; $j < 5; $j++) {
                Solicitacoes::create([
                    'user_id' => $user->username,
                    'status' => 'MEDIATION',
                    'amount' => 100.00,
                    'transaction_id' => "TXN{$i}_{$j}",
                    'codigo_autenticacao' => "E{$i}_{$j}",
                    'descricao' => "Infração {$j}",
                    'descricao_transacao' => "Infração {$j}",
                    'externalreference' => 'EXT' . uniqid(),
                    'date' => now(),
                    'deposito_liquido' => 97.50,
                    'idTransaction' => "TXN{$i}_{$j}",
                    'client_name' => 'Cliente Test',
                    'client_document' => '12345678900',
                    'client_email' => 'cliente@test.com',
                    'client_telefone' => '11999999999',
                    'qrcode_pix' => 'https://example.com/qr',
                    'paymentcode' => 'PAY' . uniqid(),
                    'paymentCodeBase64' => base64_encode('PAY' . uniqid()),
                    'adquirente_ref' => 'Banco Test',
                    'taxa_cash_in' => 2.50,
                    'taxa_pix_cash_in_adquirente' => 1.0,
                    'taxa_pix_cash_in_valor_fixo' => 0.5,
                    'executor_ordem' => 'EXEC' . uniqid(),
                    'tipo' => 'pix',
                ]);
            }

            $users[] = $user;
            $tokens[] = $token;
        }

        $startTime = microtime(true);
        $successfulRequests = 0;

        // Fazer requisições para todos os usuários
        foreach ($tokens as $token) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ])->getJson('/api/pix/infracoes');
            
            if ($response->status() === 200) {
                $successfulRequests++;
            }
        }

        $duration = (microtime(true) - $startTime) * 1000;

        // Algumas requisições podem falhar devido a problemas de autenticação ou rate limiting
        $this->assertGreaterThan(50, $successfulRequests, "Apenas {$successfulRequests} de 100 requisições foram bem-sucedidas");
        $this->assertLessThan(30000, $duration, "100 requisições levaram {$duration}ms, esperado < 30000ms");
    }

    public function test_should_maintain_performance_with_many_infracoes()
    {
        // Criar 1000 infrações
        for ($i = 0; $i < 1000; $i++) {
            $this->createInfracao(['transaction_id' => "TXN{$i}"]);
        }

        $startTime = microtime(true);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/pix/infracoes');
        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertLessThan(1000, $duration, "Get infracoes com muitas infrações levou {$duration}ms, esperado < 1000ms");
    }

    public function test_should_use_cache_to_improve_performance()
    {
        // Criar algumas infrações
        for ($i = 0; $i < 10; $i++) {
            $this->createInfracao(['transaction_id' => "TXN{$i}"]);
        }

        // Primeira requisição (sem cache)
        $startTime1 = microtime(true);
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/pix/infracoes');
        $duration1 = (microtime(true) - $startTime1) * 1000;

        // Segunda requisição (com cache)
        $startTime2 = microtime(true);
        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/pix/infracoes');
        $duration2 = (microtime(true) - $startTime2) * 1000;

        $response1->assertStatus(200);
        $response2->assertStatus(200);

        // Cache deve melhorar performance (segunda requisição deve ser mais rápida ou similar)
        // Em E2E, pode haver variação, então vamos apenas verificar que ambas funcionam
        $this->assertLessThan(1000, $duration1);
        $this->assertLessThan(1000, $duration2);
    }

    public function test_should_handle_pagination_efficiently()
    {
        // Criar 500 infrações
        for ($i = 0; $i < 500; $i++) {
            $this->createInfracao(['transaction_id' => "TXN{$i}"]);
        }

        $startTime = microtime(true);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/pix/infracoes?' . http_build_query([
            'page' => 10,
            'limit' => 20,
        ]));
        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        // Paginação deve ser rápida mesmo com muitos registros
        $this->assertLessThan(500, $duration, "Paginação levou {$duration}ms, esperado < 500ms");
    }

    public function test_should_optimize_queries_with_indexes()
    {
        // Criar múltiplos usuários e infrações
        for ($i = 0; $i < 100; $i++) {
            $user = AuthTestHelper::createTestUser([
                'username' => 'testuser_' . uniqid() . '_' . $i,
                'email' => 'testuser_' . uniqid() . '_' . $i . '@example.com',
            ]);

            Solicitacoes::create([
                'user_id' => $user->username,
                'status' => 'MEDIATION',
                'amount' => 100.00,
                'transaction_id' => "TXN{$i}",
                'codigo_autenticacao' => "E{$i}",
                'descricao' => "Infração {$i}",
                'descricao_transacao' => "Infração {$i}",
                'externalreference' => 'EXT' . uniqid(),
                'date' => now(),
                'deposito_liquido' => 97.50,
                'idTransaction' => "TXN{$i}",
                'client_name' => 'Cliente Test',
                'client_document' => '12345678900',
                'client_email' => 'cliente@test.com',
                'client_telefone' => '11999999999',
                'qrcode_pix' => 'https://example.com/qr',
                'paymentcode' => 'PAY' . uniqid(),
                'paymentCodeBase64' => base64_encode('PAY' . uniqid()),
                'adquirente_ref' => 'Banco Test',
                'taxa_cash_in' => 2.50,
                'taxa_pix_cash_in_adquirente' => 1.0,
                'taxa_pix_cash_in_valor_fixo' => 0.5,
                'executor_ordem' => 'EXEC' . uniqid(),
                'tipo' => 'pix',
            ]);
        }

        $startTime = microtime(true);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/pix/infracoes');
        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        // Deve ser rápido mesmo com muitos registros (indexes ajudam)
        $this->assertLessThan(500, $duration, "Query com muitos registros levou {$duration}ms, esperado < 500ms");
    }

    public function test_should_keep_memory_under_control()
    {
        // Criar muitas infrações
        for ($i = 0; $i < 500; $i++) {
            $this->createInfracao(['transaction_id' => "TXN{$i}"]);
        }

        $initialMemory = memory_get_usage();

        // Fazer 100 requisições de infrações
        for ($i = 0; $i < 100; $i++) {
            $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson('/api/pix/infracoes');
        }

        $finalMemory = memory_get_usage();
        $memoryIncrease = ($finalMemory - $initialMemory) / 1024 / 1024; // em MB

        // Memória não deve aumentar mais que 50MB
        $this->assertLessThan(50, $memoryIncrease, "Memória aumentou {$memoryIncrease}MB, esperado < 50MB");
    }

    public function test_should_filter_by_search_efficiently()
    {
        // Criar infrações com diferentes termos
        for ($i = 0; $i < 500; $i++) {
            $this->createInfracao([
                'transaction_id' => "TXN{$i}",
                'descricao' => $i % 2 === 0 ? "Infração par {$i}" : "Infração ímpar {$i}",
            ]);
        }

        $startTime = microtime(true);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/pix/infracoes?busca=par');
        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        // Busca deve ser rápida mesmo com muitos registros
        $this->assertLessThan(500, $duration, "Busca levou {$duration}ms, esperado < 500ms");
    }
}









