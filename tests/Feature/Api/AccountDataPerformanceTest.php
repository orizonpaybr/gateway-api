<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\UsersKey;
use Tests\Feature\Helpers\AuthTestHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/**
 * Testes de Performance e Concorrência - API de Dados da Conta
 * 
 * Cobre:
 * - Performance com múltiplas requisições
 * - Concorrência de requisições
 * - Escalabilidade
 * - Uso de cache
 * - Otimização de queries
 */
class AccountDataPerformanceTest extends TestCase
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
            'name' => 'Test User',
            'telefone' => '11999999999',
            'cpf_cnpj' => '12345678900',
        ]);

        UsersKey::factory()->create([
            'user_id' => $this->user->user_id ?? $this->user->username,
            'token' => 'test_token_' . $this->user->username,
            'secret' => 'test_secret_' . $this->user->username,
        ]);

        $this->token = AuthTestHelper::generateTestToken($this->user);
    }

    /**
     * Teste: Deve responder em menos de 200ms
     */
    public function test_should_respond_under_200ms(): void
    {
        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');

        $duration = (microtime(true) - $startTime) * 1000; // Converter para ms

        $response->assertStatus(200);
        $this->assertLessThan(200, $duration, "Resposta demorou {$duration}ms, esperado < 200ms");
    }

    /**
     * Teste: Deve usar cache para melhorar performance
     */
    public function test_should_use_cache_to_improve_performance(): void
    {
        // Primeira requisição (sem cache)
        $startTime1 = microtime(true);
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');
        $duration1 = (microtime(true) - $startTime1) * 1000;

        $response1->assertStatus(200);

        // Segunda requisição (com cache)
        $startTime2 = microtime(true);
        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');
        $duration2 = (microtime(true) - $startTime2) * 1000;

        $response2->assertStatus(200);
        
        // Cache deve melhorar performance (ou pelo menos não piorar muito)
        $this->assertLessThan(100, $duration2, "Cache não melhorou performance suficiente");
    }

    /**
     * Teste: Deve lidar com requisições concorrentes
     */
    public function test_should_handle_concurrent_requests(): void
    {
        $requests = [];
        $startTime = microtime(true);

        // Simular 20 requisições concorrentes
        for ($i = 0; $i < 20; $i++) {
            $requests[] = function () {
                return $this->withHeaders([
                    'Authorization' => 'Bearer ' . $this->token,
                ])->getJson('/api/user/profile');
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
     * Teste: Deve manter performance com múltiplos usuários
     */
    public function test_should_maintain_performance_with_multiple_users(): void
    {
        // Criar múltiplos usuários
        $users = [];
        $tokens = [];
        for ($i = 0; $i < 10; $i++) {
            $user = User::factory()->create([
                'username' => 'testuser' . $i,
                'user_id' => 'testuser' . $i,
                'email' => 'test' . $i . '@example.com',
                'status' => 1,
                'banido' => 0,
            ]);

            UsersKey::factory()->create([
                'user_id' => $user->user_id ?? $user->username,
                'token' => 'test_token_' . $user->username,
                'secret' => 'test_secret_' . $user->username,
            ]);

            $users[] = $user;
            $tokens[] = AuthTestHelper::generateTestToken($user);
        }

        $startTime = microtime(true);

        // Fazer requisições para cada usuário
        foreach ($tokens as $token) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ])->getJson('/api/user/profile');

            $response->assertStatus(200);
        }

        $duration = (microtime(true) - $startTime) * 1000;

        // Deve completar em tempo razoável
        $this->assertLessThan(2000, $duration, "Múltiplos usuários demoraram {$duration}ms");
    }

    /**
     * Teste: Deve manter memória sob controle
     */
    public function test_should_keep_memory_under_control(): void
    {
        $memoryBefore = memory_get_usage();

        // Fazer 100 requisições
        for ($i = 0; $i < 100; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson('/api/user/profile');

            $response->assertStatus(200);
        }

        $memoryAfter = memory_get_usage();
        $memoryUsed = ($memoryAfter - $memoryBefore) / 1024 / 1024; // MB

        // Não deve usar mais de 10MB para 100 requisições
        $this->assertLessThan(10, $memoryUsed, "Uso de memória foi {$memoryUsed}MB, esperado < 10MB");
    }

    /**
     * Teste: Deve invalidar cache quando necessário
     */
    public function test_should_invalidate_cache_when_needed(): void
    {
        // Primeira requisição - cria cache
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');

        $response1->assertStatus(200);
        $cacheKey = 'user_profile_' . $this->user->username;
        $this->assertNotNull(Cache::get($cacheKey));

        // Limpar cache manualmente
        Cache::forget($cacheKey);

        // Segunda requisição - deve recriar cache
        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');

        $response2->assertStatus(200);
        $this->assertNotNull(Cache::get($cacheKey));
    }

    /**
     * Teste: Deve processar requisições rapidamente mesmo com dados complexos
     */
    public function test_should_process_requests_quickly_even_with_complex_data(): void
    {
        // Adicionar dados complexos ao usuário (limite de campo name é menor)
        $this->user->name = str_repeat('A', 100);
        $this->user->save();

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');

        $duration = (microtime(true) - $startTime) * 1000;

        $response->assertStatus(200);
        $this->assertLessThan(300, $duration, "Dados complexos demoraram {$duration}ms");
    }

    /**
     * Teste: Deve escalar com muitos usuários simultâneos
     */
    public function test_should_scale_with_many_simultaneous_users(): void
    {
        // Criar 50 usuários
        $tokens = [];
        for ($i = 0; $i < 50; $i++) {
            $user = User::factory()->create([
                'username' => 'user' . $i,
                'user_id' => 'user' . $i,
                'email' => 'user' . $i . '@example.com',
                'status' => 1,
                'banido' => 0,
            ]);

            UsersKey::factory()->create([
                'user_id' => $user->user_id ?? $user->username,
                'token' => 'test_token_' . $user->username,
                'secret' => 'test_secret_' . $user->username,
            ]);

            $tokens[] = AuthTestHelper::generateTestToken($user);
        }

        $startTime = microtime(true);

        // Fazer requisições para todos os usuários
        foreach ($tokens as $token) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ])->getJson('/api/user/profile');

            $response->assertStatus(200);
        }

        $duration = (microtime(true) - $startTime) * 1000;

        // Deve completar em tempo razoável
        $this->assertLessThan(5000, $duration, "50 usuários simultâneos demoraram {$duration}ms");
    }
}

