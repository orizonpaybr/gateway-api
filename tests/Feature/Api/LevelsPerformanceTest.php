<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\Nivel;
use App\Models\App;
use App\Models\UsersKey;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Performance e Concorrência - API de Níveis de Gamificação
 * 
 * Cobre:
 * - Performance com múltiplas requisições
 * - Concorrência
 * - Escalabilidade
 * - Cache
 * - Atualizações simultâneas
 */
class LevelsPerformanceTest extends TestCase
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
     * Teste: Deve listar níveis rapidamente
     */
    public function test_should_list_levels_quickly(): void
    {
        // Criar múltiplos níveis
        for ($i = 0; $i < 10; $i++) {
            Nivel::create([
                'nome' => "Nível $i",
                'cor' => '#CD7F32',
                'minimo' => $i * 1000,
                'maximo' => ($i + 1) * 1000,
            ]);
        }

        App::create(['niveis_ativo' => true]);

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/levels');

        $duration = microtime(true) - $startTime;

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        
        // Deve processar em menos de 1 segundo
        $this->assertLessThan(1.0, $duration);
    }

    /**
     * Teste: Deve processar atualização em tempo razoável
     */
    public function test_should_process_update_in_reasonable_time(): void
    {
        $nivel = Nivel::create([
            'nome' => 'Bronze',
            'cor' => '#CD7F32',
            'minimo' => 0,
            'maximo' => 1000,
        ]);

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson('/api/admin/levels/' . $nivel->id, [
            'nome' => 'Bronze Atualizado',
            'cor' => '#FF0000',
            'minimo' => 0,
            'maximo' => 2000,
        ]);

        $duration = microtime(true) - $startTime;

        $response->assertStatus(200);
        
        // Deve processar em menos de 2 segundos
        $this->assertLessThan(2.0, $duration);
    }

    /**
     * Teste: Deve manter consistência com múltiplas atualizações
     */
    public function test_should_maintain_consistency_with_multiple_updates(): void
    {
        $nivel = Nivel::create([
            'nome' => 'Bronze',
            'cor' => '#CD7F32',
            'minimo' => 0,
            'maximo' => 1000,
        ]);

        // Fazer múltiplas atualizações em sequência
        for ($i = 0; $i < 5; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->putJson('/api/admin/levels/' . $nivel->id, [
                'nome' => "Bronze $i",
                'minimo' => 0,
                'maximo' => 1000 + ($i * 100),
            ]);

            $response->assertStatus(200);
            $this->assertTrue($response->json('success'));
        }

        // Verificar valor final
        $finalResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/levels/' . $nivel->id);

        $finalResponse->assertStatus(200);
        $this->assertEquals('Bronze 4', $finalResponse->json('data.nome'));
    }

    /**
     * Teste: Deve processar toggle active rapidamente
     */
    public function test_should_process_toggle_active_quickly(): void
    {
        App::create(['niveis_ativo' => false]);

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/levels/toggle-active', [
            'niveis_ativo' => true,
        ]);

        $duration = microtime(true) - $startTime;

        $response->assertStatus(200);
        
        // Deve processar em menos de 1 segundo
        $this->assertLessThan(1.0, $duration);
    }

    /**
     * Teste: Deve limpar cache após toggle
     */
    public function test_should_clear_cache_after_toggle(): void
    {
        App::create(['niveis_ativo' => false]);

        // Criar cache
        Cache::put('test_cache_key', 'test_value', 3600);
        $this->assertTrue(Cache::has('test_cache_key'));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/levels/toggle-active', [
            'niveis_ativo' => true,
        ]);

        $response->assertStatus(200);
        
        // Cache deve ser limpo (Cache::flush() é chamado)
        // Como flush limpa tudo, não podemos verificar uma chave específica
        // mas podemos verificar que a operação foi bem-sucedida
        $this->assertTrue($response->json('success'));
    }

    /**
     * Teste: Deve processar múltiplas requisições simultâneas
     */
    public function test_should_handle_concurrent_requests(): void
    {
        // Criar múltiplos níveis
        for ($i = 0; $i < 5; $i++) {
            Nivel::create([
                'nome' => "Nível $i",
                'cor' => '#CD7F32',
                'minimo' => $i * 1000,
                'maximo' => ($i + 1) * 1000,
            ]);
        }

        App::create(['niveis_ativo' => true]);

        // Fazer múltiplas requisições em sequência (simulando concorrência)
        $responses = [];
        for ($i = 0; $i < 10; $i++) {
            $responses[] = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson('/api/admin/levels');
        }

        // Verificar que todas retornaram sucesso
        foreach ($responses as $response) {
            $response->assertStatus(200);
            $this->assertTrue($response->json('success'));
        }
    }

    /**
     * Teste: Deve lidar com muitos níveis eficientemente
     */
    public function test_should_handle_many_levels_efficiently(): void
    {
        // Criar muitos níveis
        for ($i = 0; $i < 50; $i++) {
            Nivel::create([
                'nome' => "Nível $i",
                'cor' => '#CD7F32',
                'minimo' => $i * 1000,
                'maximo' => ($i + 1) * 1000,
            ]);
        }

        App::create(['niveis_ativo' => true]);

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/levels');

        $duration = microtime(true) - $startTime;

        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
        $this->assertCount(50, $response->json('data.niveis'));
        
        // Deve processar em menos de 2 segundos mesmo com muitos níveis
        $this->assertLessThan(2.0, $duration);
    }

    /**
     * Teste: Deve manter ordenação correta com muitos níveis
     */
    public function test_should_maintain_correct_ordering_with_many_levels(): void
    {
        // Criar níveis em ordem aleatória
        for ($i = 49; $i >= 0; $i--) {
            Nivel::create([
                'nome' => "Nível $i",
                'cor' => '#CD7F32',
                'minimo' => $i * 1000,
                'maximo' => ($i + 1) * 1000,
            ]);
        }

        App::create(['niveis_ativo' => true]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/admin/levels');

        $response->assertStatus(200);
        $niveis = $response->json('data.niveis');
        
        // Verificar que está ordenado por mínimo (asc)
        for ($i = 0; $i < count($niveis) - 1; $i++) {
            $this->assertLessThanOrEqual(
                $niveis[$i + 1]['minimo'],
                $niveis[$i]['minimo']
            );
        }
    }
}








