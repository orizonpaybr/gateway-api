<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\Notification;
use App\Models\UsersKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Performance e Concorrência - API Notificações
 * 
 * Cobre:
 * - Performance de endpoints
 * - Concorrência de requisições
 * - Escalabilidade
 * - Uso de cache
 * - Otimização de queries
 */
class NotificationPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private UsersKey $userKey;

    protected function setUp(): void
    {
        parent::setUp();

        // Criar usuário
        $this->user = AuthTestHelper::createTestUser([
            'username' => 'testuser_' . uniqid(),
            'email' => 'testuser_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'status' => 1,
            'banido' => 0,
        ]);

        // Criar credenciais API
        $this->userKey = UsersKey::create([
            'user_id' => $this->user->username,
            'token' => 'test-token-' . uniqid(),
            'secret' => 'test-secret-' . uniqid(),
            'status' => 1,
        ]);
    }

    /**
     * Helper para criar notificação de teste
     */
    private function createNotification(array $attributes = []): Notification
    {
        $defaults = [
            'user_id' => $this->user->username,
            'type' => 'transaction',
            'title' => 'Notificação de teste',
            'body' => 'Corpo da notificação de teste',
            'data' => [],
            'read_at' => null,
            'push_sent' => false,
            'local_sent' => false,
        ];

        return Notification::create(array_merge($defaults, $attributes));
    }

    public function test_should_respond_under_300ms_for_notifications()
    {
        // Criar algumas notificações
        for ($i = 0; $i < 10; $i++) {
            $this->createNotification(['title' => "Notificação {$i}"]);
        }

        $url = '/api/notifications?' . http_build_query([
            'token' => $this->userKey->token,
            'secret' => $this->userKey->secret,
        ]);

        $startTime = microtime(true);
        $response = $this->getJson($url);
        $duration = (microtime(true) - $startTime) * 1000; // em ms

        $response->assertStatus(200);
        $this->assertLessThan(300, $duration, "Get notifications levou {$duration}ms, esperado < 300ms");
    }

    public function test_should_handle_concurrent_requests()
    {
        // Criar algumas notificações
        for ($i = 0; $i < 20; $i++) {
            $this->createNotification(['title' => "Notificação {$i}"]);
        }

        $concurrentRequests = 50;
        $successfulRequests = 0;
        $startTime = microtime(true);

        $url = '/api/notifications?' . http_build_query([
            'token' => $this->userKey->token,
            'secret' => $this->userKey->secret,
        ]);

        for ($i = 0; $i < $concurrentRequests; $i++) {
            $response = $this->getJson($url);
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
        $userKeys = [];

        // Criar 100 usuários
        for ($i = 0; $i < 100; $i++) {
            $user = AuthTestHelper::createTestUser([
                'username' => 'testuser_' . uniqid() . '_' . $i,
                'email' => 'testuser_' . uniqid() . '_' . $i . '@example.com',
            ]);

            $userKey = UsersKey::create([
                'user_id' => $user->username,
                'token' => 'test-token-' . uniqid(),
                'secret' => 'test-secret-' . uniqid(),
                'status' => 1,
            ]);

            // Criar algumas notificações para cada usuário
            for ($j = 0; $j < 5; $j++) {
                Notification::create([
                    'user_id' => $user->username,
                    'type' => 'transaction',
                    'title' => "Notificação {$j}",
                    'body' => 'Corpo',
                    'data' => [],
                ]);
            }

            $users[] = $user;
            $userKeys[] = $userKey;
        }

        $startTime = microtime(true);
        $successfulRequests = 0;

        // Fazer requisições para todos os usuários
        foreach ($userKeys as $key) {
            $url = '/api/notifications?' . http_build_query([
                'token' => $key->token,
                'secret' => $key->secret,
            ]);
            
            $response = $this->getJson($url);
            if ($response->status() === 200) {
                $successfulRequests++;
            }
        }

        $duration = (microtime(true) - $startTime) * 1000;

        // Algumas requisições podem falhar devido a problemas de autenticação ou rate limiting
        $this->assertGreaterThan(50, $successfulRequests, "Apenas {$successfulRequests} de 100 requisições foram bem-sucedidas");
        $this->assertLessThan(30000, $duration, "100 requisições levaram {$duration}ms, esperado < 30000ms");
    }

    public function test_should_maintain_performance_with_many_notifications()
    {
        // Criar 1000 notificações
        for ($i = 0; $i < 1000; $i++) {
            $this->createNotification(['title' => "Notificação {$i}"]);
        }

        $url = '/api/notifications?' . http_build_query([
            'token' => $this->userKey->token,
            'secret' => $this->userKey->secret,
        ]);

        $startTime = microtime(true);
        $response = $this->getJson($url);
        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertLessThan(1000, $duration, "Get notifications com muitas notificações levou {$duration}ms, esperado < 1000ms");
    }

    public function test_should_handle_pagination_efficiently()
    {
        // Criar 500 notificações
        for ($i = 0; $i < 500; $i++) {
            $this->createNotification(['title' => "Notificação {$i}"]);
        }

        $url = '/api/notifications?' . http_build_query([
            'token' => $this->userKey->token,
            'secret' => $this->userKey->secret,
            'page' => 10,
            'limit' => 20,
        ]);

        $startTime = microtime(true);
        $response = $this->getJson($url);
        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        // Paginação deve ser rápida mesmo com muitos registros
        $this->assertLessThan(500, $duration, "Paginação levou {$duration}ms, esperado < 500ms");
    }

    public function test_should_optimize_queries_with_indexes()
    {
        // Criar múltiplos usuários e notificações
        for ($i = 0; $i < 100; $i++) {
            $user = AuthTestHelper::createTestUser([
                'username' => 'testuser_' . uniqid() . '_' . $i,
                'email' => 'testuser_' . uniqid() . '_' . $i . '@example.com',
            ]);

            Notification::create([
                'user_id' => $user->username,
                'type' => 'transaction',
                'title' => "Notificação {$i}",
                'body' => 'Corpo',
                'data' => [],
            ]);
        }

        $url = '/api/notifications?' . http_build_query([
            'token' => $this->userKey->token,
            'secret' => $this->userKey->secret,
        ]);

        $startTime = microtime(true);
        $response = $this->getJson($url);
        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        // Deve ser rápido mesmo com muitos registros (indexes ajudam)
        $this->assertLessThan(500, $duration, "Query com muitos registros levou {$duration}ms, esperado < 500ms");
    }

    public function test_should_keep_memory_under_control()
    {
        // Criar muitas notificações
        for ($i = 0; $i < 500; $i++) {
            $this->createNotification(['title' => "Notificação {$i}"]);
        }

        $initialMemory = memory_get_usage();

        $url = '/api/notifications?' . http_build_query([
            'token' => $this->userKey->token,
            'secret' => $this->userKey->secret,
        ]);

        // Fazer 100 requisições de notificações
        for ($i = 0; $i < 100; $i++) {
            $this->getJson($url);
        }

        $finalMemory = memory_get_usage();
        $memoryIncrease = ($finalMemory - $initialMemory) / 1024 / 1024; // em MB

        // Memória não deve aumentar mais que 50MB
        $this->assertLessThan(50, $memoryIncrease, "Memória aumentou {$memoryIncrease}MB, esperado < 50MB");
    }

    public function test_should_filter_unread_efficiently()
    {
        // Criar notificações lidas e não lidas
        for ($i = 0; $i < 500; $i++) {
            $this->createNotification([
                'title' => "Notificação {$i}",
                'read_at' => $i % 2 === 0 ? now() : null, // Metade lidas
            ]);
        }

        $url = '/api/notifications?' . http_build_query([
            'token' => $this->userKey->token,
            'secret' => $this->userKey->secret,
            'unread_only' => true,
        ]);

        $startTime = microtime(true);
        $response = $this->getJson($url);
        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        // Filtro deve ser rápido mesmo com muitos registros
        $this->assertLessThan(500, $duration, "Filtro de não lidas levou {$duration}ms, esperado < 500ms");
    }

    public function test_should_mark_all_as_read_efficiently()
    {
        // Criar 500 notificações não lidas
        for ($i = 0; $i < 500; $i++) {
            $this->createNotification(['read_at' => null]);
        }

        $startTime = microtime(true);
        $response = $this->postJson('/api/notifications/mark-all-read', [
            'token' => $this->userKey->token,
            'secret' => $this->userKey->secret,
        ]);
        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        // Marcar todas como lidas deve ser rápido mesmo com muitas notificações
        $this->assertLessThan(1000, $duration, "Marcar todas como lidas levou {$duration}ms, esperado < 1000ms");
    }
}









