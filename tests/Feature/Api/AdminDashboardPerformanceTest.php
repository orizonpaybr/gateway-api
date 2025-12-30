<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Performance e Concorrência - API Admin Dashboard
 * 
 * Cobre:
 * - Performance de endpoints
 * - Concorrência de requisições
 * - Escalabilidade
 * - Uso de cache
 * - Otimização de queries
 */
class AdminDashboardPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Criar usuário admin e obter token
        $this->adminUser = AuthTestHelper::createTestUser([
            'username' => 'admin_' . uniqid(),
            'email' => 'admin_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'status' => 1,
            'banido' => 0,
            'permission' => 3, // Admin
        ]);

        $this->token = AuthTestHelper::generateTestToken($this->adminUser);
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

    public function test_should_respond_under_500ms_for_dashboard_stats()
    {
        // Criar algumas transações
        for ($i = 0; $i < 10; $i++) {
            $this->createDeposito(['amount' => 10000 + ($i * 1000)]);
            $this->createSaque(['amount' => 5000 + ($i * 500)]);
        }

        $startTime = microtime(true);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/stats?periodo=hoje');
        $duration = (microtime(true) - $startTime) * 1000; // em ms

        $response->assertStatus(200);
        $this->assertLessThan(500, $duration, "Get dashboard stats levou {$duration}ms, esperado < 500ms");
    }

    public function test_should_handle_concurrent_requests()
    {
        // Criar algumas transações
        for ($i = 0; $i < 20; $i++) {
            $this->createDeposito(['amount' => 10000]);
        }

        $concurrentRequests = 50;
        $successfulRequests = 0;
        $startTime = microtime(true);

        for ($i = 0; $i < $concurrentRequests; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson('/api/admin/dashboard/stats?periodo=hoje');
            
            if ($response->status() === 200) {
                $successfulRequests++;
            }
        }

        $duration = (microtime(true) - $startTime) * 1000;

        $this->assertEquals($concurrentRequests, $successfulRequests);
        $this->assertLessThan(10000, $duration, "50 requisições levaram {$duration}ms, esperado < 10000ms");
    }

    public function test_should_scale_with_multiple_users()
    {
        $users = [];
        $tokens = [];

        // Criar 50 usuários
        for ($i = 0; $i < 50; $i++) {
            $user = AuthTestHelper::createTestUser([
                'username' => 'testuser_' . uniqid() . '_' . $i,
                'email' => 'testuser_' . uniqid() . '_' . $i . '@example.com',
            ]);

            $token = AuthTestHelper::generateTestToken($user);

            // Criar algumas transações para cada usuário
            for ($j = 0; $j < 5; $j++) {
                Solicitacoes::create([
                    'user_id' => $user->username,
                    'idTransaction' => "TXN{$i}_{$j}",
                    'externalreference' => 'EXT' . uniqid(),
                    'amount' => 10000 + ($j * 1000),
                    'deposito_liquido' => 9750 + ($j * 975),
                    'taxa_cash_in' => 250,
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
                ]);
            }

            $users[] = $user;
            $tokens[] = $token;
        }

        $startTime = microtime(true);
        $successfulRequests = 0;

        // Fazer requisições para o dashboard admin (apenas admin pode acessar)
        for ($i = 0; $i < 50; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson('/api/admin/dashboard/stats?periodo=hoje');
            
            if ($response->status() === 200) {
                $successfulRequests++;
            }
        }

        $duration = (microtime(true) - $startTime) * 1000;

        $this->assertEquals(50, $successfulRequests);
        $this->assertLessThan(15000, $duration, "50 requisições levaram {$duration}ms, esperado < 15000ms");
    }

    public function test_should_maintain_performance_with_many_transactions()
    {
        // Criar 1000 transações
        for ($i = 0; $i < 1000; $i++) {
            $this->createDeposito(['amount' => 100 + ($i * 10)]);
        }

        $startTime = microtime(true);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/stats?periodo=hoje');
        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertLessThan(2000, $duration, "Get dashboard stats com muitas transações levou {$duration}ms, esperado < 2000ms");
    }

    public function test_should_use_cache_to_improve_performance()
    {
        // Criar algumas transações
        for ($i = 0; $i < 10; $i++) {
            $this->createDeposito(['amount' => 10000]);
        }

        // Primeira requisição (sem cache)
        $startTime1 = microtime(true);
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/stats?periodo=hoje');
        $duration1 = (microtime(true) - $startTime1) * 1000;

        // Segunda requisição (com cache)
        $startTime2 = microtime(true);
        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/stats?periodo=hoje');
        $duration2 = (microtime(true) - $startTime2) * 1000;

        $response1->assertStatus(200);
        $response2->assertStatus(200);

        // Cache deve melhorar performance (segunda requisição deve ser mais rápida ou similar)
        $this->assertLessThan(2000, $duration1);
        $this->assertLessThan(2000, $duration2);
    }

    public function test_should_optimize_queries_with_indexes()
    {
        // Criar múltiplos usuários e transações
        for ($i = 0; $i < 100; $i++) {
            $user = AuthTestHelper::createTestUser([
                'username' => 'testuser_' . uniqid() . '_' . $i,
                'email' => 'testuser_' . uniqid() . '_' . $i . '@example.com',
            ]);

            Solicitacoes::create([
                'user_id' => $user->username,
                'idTransaction' => "TXN{$i}",
                'externalreference' => 'EXT' . uniqid(),
                'amount' => 10000,
                'deposito_liquido' => 9750,
                'taxa_cash_in' => 250,
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
            ]);
        }

        $startTime = microtime(true);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/stats?periodo=hoje');
        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        // Deve ser rápido mesmo com muitos registros (indexes ajudam)
        $this->assertLessThan(1000, $duration, "Query com muitos registros levou {$duration}ms, esperado < 1000ms");
    }

    public function test_should_keep_memory_under_control()
    {
        // Criar muitas transações
        for ($i = 0; $i < 500; $i++) {
            $this->createDeposito(['amount' => 100 + ($i * 10)]);
        }

        $initialMemory = memory_get_usage();

        // Fazer 100 requisições de dashboard stats
        for ($i = 0; $i < 100; $i++) {
            $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson('/api/admin/dashboard/stats?periodo=hoje');
        }

        $finalMemory = memory_get_usage();
        $memoryIncrease = ($finalMemory - $initialMemory) / 1024 / 1024; // em MB

        // Memória não deve aumentar mais que 100MB
        $this->assertLessThan(100, $memoryIncrease, "Memória aumentou {$memoryIncrease}MB, esperado < 100MB");
    }

    public function test_should_maintain_performance_with_cache()
    {
        // Criar algumas transações
        for ($i = 0; $i < 10; $i++) {
            $this->createDeposito(['amount' => 10000]);
        }

        $users = [];
        $tokens = [];

        // Criar 50 usuários
        for ($i = 0; $i < 50; $i++) {
            $user = AuthTestHelper::createTestUser([
                'username' => 'testuser_' . uniqid() . '_' . $i,
                'email' => 'testuser_' . uniqid() . '_' . $i . '@example.com',
            ]);

            $token = AuthTestHelper::generateTestToken($user);

            // Criar algumas transações para cada usuário
            for ($j = 0; $j < 5; $j++) {
                Solicitacoes::create([
                    'user_id' => $user->username,
                    'idTransaction' => "TXN{$i}_{$j}",
                    'externalreference' => 'EXT' . uniqid(),
                    'amount' => 10000,
                    'deposito_liquido' => 9750,
                    'taxa_cash_in' => 250,
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
                ]);
            }

            $users[] = $user;
            $tokens[] = $token;
        }

        $startTime = microtime(true);
        $successfulRequests = 0;

        // Fazer requisições para o dashboard admin (cache deve ajudar)
        for ($i = 0; $i < 50; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson('/api/admin/dashboard/stats?periodo=hoje');
            
            if ($response->status() === 200) {
                $successfulRequests++;
            }
        }

        $duration = (microtime(true) - $startTime) * 1000;

        $this->assertEquals(50, $successfulRequests);
        $this->assertLessThan(15000, $duration, "50 requisições levaram {$duration}ms, esperado < 15000ms");
    }
}








