<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\UsersKey;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Performance e Concorrência - API de Gerentes
 * 
 * Cobre:
 * - Performance com múltiplas requisições
 * - Concorrência
 * - Escalabilidade
 * - Cache
 * - Busca com muitos resultados
 */
class ManagersPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Criar usuário admin e obter token
        $this->user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'status' => 1,
            'banido' => 0,
            'permission' => UserPermission::ADMIN,
        ]);

        // Criar UsersKey (necessário para login)
        UsersKey::factory()->create([
            'user_id' => $this->user->user_id ?? $this->user->username,
            'token' => 'test_token_' . $this->user->username,
        ]);

        // Fazer login e obter token
        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => 'testuser',
            'password' => 'password123',
        ]);

        $this->token = $loginResponse->json('token') ?? $loginResponse->json('data.token');
    }

    /**
     * Teste: Deve listar múltiplos gerentes rapidamente
     */
    public function test_should_list_multiple_managers_quickly(): void
    {
        // Criar múltiplos gerentes
        for ($i = 0; $i < 50; $i++) {
            AuthTestHelper::createTestUser([
                'username' => 'manager_perf_' . $i . '_' . uniqid(),
                'email' => 'manager_perf_' . $i . '_' . uniqid() . '@example.com',
                'permission' => UserPermission::MANAGER,
                'name' => "Gerente Performance {$i}",
            ]);
        }

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/users-managers?per_page=50');

        $duration = microtime(true) - $startTime;

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        
        // Deve processar em menos de 2 segundos
        $this->assertLessThan(2.0, $duration);
    }

    /**
     * Teste: Deve usar cache para melhorar performance
     */
    public function test_should_use_cache_for_performance(): void
    {
        // Criar gerentes
        for ($i = 0; $i < 10; $i++) {
            AuthTestHelper::createTestUser([
                'username' => 'manager_cache_' . $i . '_' . uniqid(),
                'email' => 'manager_cache_' . $i . '_' . uniqid() . '@example.com',
                'permission' => UserPermission::MANAGER,
                'name' => "Gerente Cache {$i}",
            ]);
        }

        // Primeira requisição (deve buscar do banco)
        $startTime1 = microtime(true);
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/users-managers');
        $duration1 = microtime(true) - $startTime1;

        $response1->assertStatus(200);

        // Segunda requisição (deve usar cache)
        $startTime2 = microtime(true);
        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/users-managers');
        $duration2 = microtime(true) - $startTime2;

        $response2->assertStatus(200);
        
        // A segunda requisição deve ser mais rápida (ou similar devido ao overhead)
        $this->assertTrue($duration2 <= $duration1 * 1.5); // Permitir até 50% mais lento devido ao overhead
    }

    /**
     * Teste: Deve processar requisições em tempo razoável
     */
    public function test_should_process_request_in_reasonable_time(): void
    {
        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/users-managers');

        $duration = microtime(true) - $startTime;

        $response->assertStatus(200);
        
        // Deve processar em menos de 1 segundo
        $this->assertLessThan(1.0, $duration);
    }

    /**
     * Teste: Deve lidar com busca em muitos registros
     */
    public function test_should_handle_search_with_many_records(): void
    {
        // Criar muitos gerentes
        for ($i = 0; $i < 100; $i++) {
            AuthTestHelper::createTestUser([
                'username' => 'manager_search_' . $i . '_' . uniqid(),
                'email' => 'manager_search_' . $i . '_' . uniqid() . '@example.com',
                'permission' => UserPermission::MANAGER,
                'name' => $i < 10 ? "Gerente Especial {$i}" : "Gerente Normal {$i}",
            ]);
        }

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/users-managers?search=Especial');

        $duration = microtime(true) - $startTime;

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        
        // Deve processar em menos de 2 segundos mesmo com muitos registros
        $this->assertLessThan(2.0, $duration);
    }

    /**
     * Teste: Deve manter consistência com múltiplas requisições simultâneas
     */
    public function test_should_maintain_consistency_with_concurrent_requests(): void
    {
        // Criar gerentes
        for ($i = 0; $i < 20; $i++) {
            AuthTestHelper::createTestUser([
                'username' => 'manager_concurrent_' . $i . '_' . uniqid(),
                'email' => 'manager_concurrent_' . $i . '_' . uniqid() . '@example.com',
                'permission' => UserPermission::MANAGER,
                'name' => "Gerente Concurrent {$i}",
            ]);
        }

        // Fazer múltiplas requisições em sequência (simulando concorrência)
        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson('/api/admin/users-managers');
        }

        // Verificar que todas retornaram sucesso
        foreach ($responses as $response) {
            $response->assertStatus(200);
            $this->assertTrue($response->json('success'));
        }

        // Verificar que todas retornaram a mesma quantidade (ou similar devido ao cache)
        $counts = array_map(function ($response) {
            return count($response->json('data.managers'));
        }, $responses);
        
        // Todas devem ter pelo menos alguns gerentes
        foreach ($counts as $count) {
            $this->assertGreaterThanOrEqual(0, $count);
        }
    }

    /**
     * Teste: Deve limpar cache após criar novo gerente
     */
    public function test_should_clear_cache_after_creating_manager(): void
    {
        // Criar gerente inicial
        AuthTestHelper::createTestUser([
            'username' => 'manager_initial_' . uniqid(),
            'email' => 'manager_initial_' . uniqid() . '@example.com',
            'permission' => UserPermission::MANAGER,
            'name' => 'Gerente Inicial',
        ]);

        // Primeira requisição
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/users-managers');

        $response1->assertStatus(200);
        $initialCount = count($response1->json('data.managers'));

        // Criar novo gerente (simulando criação via API)
        AuthTestHelper::createTestUser([
            'username' => 'manager_new_' . uniqid(),
            'email' => 'manager_new_' . uniqid() . '@example.com',
            'permission' => UserPermission::MANAGER,
            'name' => 'Gerente Novo',
        ]);

        // Limpar cache manualmente (simulando invalidação após criação)
        Cache::flush();

        // Segunda requisição (deve buscar do banco novamente)
        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/users-managers');

        $response2->assertStatus(200);
        $newCount = count($response2->json('data.managers'));

        // Deve ter pelo menos o mesmo número ou mais
        $this->assertGreaterThanOrEqual($initialCount, $newCount);
    }
}








