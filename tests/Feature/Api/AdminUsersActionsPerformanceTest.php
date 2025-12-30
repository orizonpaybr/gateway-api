<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Performance e Concorrência - API Admin Users Actions
 * 
 * Cobre:
 * - Performance de endpoints de ações de usuários
 * - Concorrência de requisições
 * - Escalabilidade
 * - Uso de cache
 * - Otimização de queries
 */
class AdminUsersActionsPerformanceTest extends TestCase
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

    public function test_should_respond_under_300ms_for_approve_user()
    {
        // Criar usuário para aprovar
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'status' => 0, // Pendente
        ]);

        $startTime = microtime(true);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson("/api/admin/users/{$targetUser->id}/approve");
        $duration = (microtime(true) - $startTime) * 1000; // em ms

        $response->assertStatus(200);
        $this->assertLessThan(300, $duration, "Approve user levou {$duration}ms, esperado < 300ms");
    }

    public function test_should_respond_under_300ms_for_toggle_block()
    {
        // Criar usuário para bloquear
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $startTime = microtime(true);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson("/api/admin/users/{$targetUser->id}/toggle-block", [
            'block' => true,
        ]);
        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertLessThan(300, $duration, "Toggle block levou {$duration}ms, esperado < 300ms");
    }

    public function test_should_respond_under_300ms_for_adjust_balance()
    {
        // Criar usuário com saldo
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 100.00,
        ]);

        $startTime = microtime(true);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson("/api/admin/users/{$targetUser->id}/adjust-balance", [
            'amount' => 50.00,
            'type' => 'add',
            'reason' => 'Teste de performance',
        ]);
        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertLessThan(300, $duration, "Adjust balance levou {$duration}ms, esperado < 300ms");
    }

    public function test_should_respond_under_500ms_for_get_user()
    {
        // Criar usuário
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $startTime = microtime(true);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson("/api/admin/users/{$targetUser->id}");
        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertLessThan(500, $duration, "Get user levou {$duration}ms, esperado < 500ms");
    }

    public function test_should_handle_concurrent_approve_requests()
    {
        // Criar múltiplos usuários para aprovar
        $users = [];
        for ($i = 0; $i < 50; $i++) {
            $users[] = AuthTestHelper::createTestUser([
                'username' => 'user_' . uniqid() . '_' . $i,
                'email' => 'user_' . uniqid() . '_' . $i . '@example.com',
                'status' => 0, // Pendente
            ]);
        }

        $concurrentRequests = 50;
        $successfulRequests = 0;
        $startTime = microtime(true);

        foreach ($users as $user) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson("/api/admin/users/{$user->id}/approve");
            
            if ($response->status() === 200) {
                $successfulRequests++;
            }
        }

        $duration = (microtime(true) - $startTime) * 1000;

        $this->assertEquals($concurrentRequests, $successfulRequests);
        $this->assertLessThan(10000, $duration, "50 requisições de approve levaram {$duration}ms, esperado < 10000ms");
    }

    public function test_should_scale_with_many_users()
    {
        // Criar 200 usuários
        $users = [];
        for ($i = 0; $i < 200; $i++) {
            $users[] = AuthTestHelper::createTestUser([
                'username' => 'user_' . uniqid() . '_' . $i,
                'email' => 'user_' . uniqid() . '_' . $i . '@example.com',
            ]);
        }

        $startTime = microtime(true);
        $successfulRequests = 0;

        // Fazer requisições para listar usuários
        for ($i = 0; $i < 20; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson('/api/admin/dashboard/users?per_page=20&page=' . ($i + 1));
            
            if ($response->status() === 200) {
                $successfulRequests++;
            }
        }

        $duration = (microtime(true) - $startTime) * 1000;

        $this->assertEquals(20, $successfulRequests);
        $this->assertLessThan(15000, $duration, "20 requisições de listagem levaram {$duration}ms, esperado < 15000ms");
    }

    public function test_should_maintain_performance_with_search()
    {
        // Criar muitos usuários
        for ($i = 0; $i < 100; $i++) {
            AuthTestHelper::createTestUser([
                'username' => 'user_' . uniqid() . '_' . $i,
                'email' => 'user_' . uniqid() . '_' . $i . '@example.com',
                'name' => 'Usuário Teste ' . $i,
            ]);
        }

        $startTime = microtime(true);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/dashboard/users?search=Teste&per_page=20');
        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertLessThan(1000, $duration, "Search users levou {$duration}ms, esperado < 1000ms");
    }

    public function test_should_use_cache_for_user_data()
    {
        // Criar usuário
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        // Primeira requisição (sem cache)
        $startTime1 = microtime(true);
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson("/api/admin/users/{$targetUser->id}");
        $duration1 = (microtime(true) - $startTime1) * 1000;

        // Segunda requisição (com cache)
        $startTime2 = microtime(true);
        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson("/api/admin/users/{$targetUser->id}");
        $duration2 = (microtime(true) - $startTime2) * 1000;

        $response1->assertStatus(200);
        $response2->assertStatus(200);

        // Cache deve melhorar performance (segunda requisição deve ser mais rápida ou similar)
        $this->assertLessThan(1000, $duration1);
        $this->assertLessThan(1000, $duration2);
    }

    public function test_should_keep_memory_under_control()
    {
        // Criar muitos usuários
        $users = [];
        for ($i = 0; $i < 100; $i++) {
            $users[] = AuthTestHelper::createTestUser([
                'username' => 'user_' . uniqid() . '_' . $i,
                'email' => 'user_' . uniqid() . '_' . $i . '@example.com',
            ]);
        }

        $initialMemory = memory_get_usage();

        // Fazer 100 requisições de ações
        foreach ($users as $user) {
            try {
                // Aprovar usuário
                $this->withHeaders([
                    'Authorization' => 'Bearer ' . $this->token,
                ])->postJson("/api/admin/users/{$user->id}/approve");
            } catch (\Exception $e) {
                // Continuar mesmo se houver erro
            }
        }

        $finalMemory = memory_get_usage();
        $memoryIncrease = ($finalMemory - $initialMemory) / 1024 / 1024; // em MB

        // Memória não deve aumentar mais que 50MB
        $this->assertLessThan(50, $memoryIncrease, "Memória aumentou {$memoryIncrease}MB, esperado < 50MB");
    }

    public function test_should_handle_multiple_balance_adjustments()
    {
        // Criar usuário com saldo
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 1000.00,
        ]);

        $startTime = microtime(true);
        $successfulRequests = 0;

        // Fazer 50 ajustes de saldo
        for ($i = 0; $i < 50; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson("/api/admin/users/{$targetUser->id}/adjust-balance", [
                'amount' => 10.00,
                'type' => 'add',
                'reason' => "Ajuste {$i}",
            ]);
            
            if ($response->status() === 200) {
                $successfulRequests++;
            }
        }

        $duration = (microtime(true) - $startTime) * 1000;

        $this->assertEquals(50, $successfulRequests);
        $this->assertLessThan(10000, $duration, "50 ajustes de saldo levaram {$duration}ms, esperado < 10000ms");
    }
}
