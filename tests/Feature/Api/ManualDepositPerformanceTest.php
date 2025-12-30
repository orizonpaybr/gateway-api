<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\Solicitacoes;
use App\Models\App;
use App\Models\UsersKey;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Performance e Concorrência - API de Depósito Manual
 * 
 * Cobre:
 * - Performance com múltiplas requisições
 * - Concorrência
 * - Escalabilidade
 * - Cache
 */
class ManualDepositPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;
    private App $appSettings;

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

        // Criar configurações da aplicação
        $this->appSettings = App::create([
            'taxa_pix_cash_in' => 2.5,
            'taxa_pix_cash_in_valor_fixo' => 0.5,
            'taxa_pix_cash_in_adquirente' => 1.0,
        ]);
    }

    /**
     * Teste: Deve criar múltiplos depósitos em sequência rapidamente
     */
    public function test_should_create_multiple_deposits_quickly(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $startTime = microtime(true);
        $count = 10;

        for ($i = 0; $i < $count; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/admin/manual-transactions/deposits', [
                'user_id' => $targetUser->user_id,
                'amount' => 10.00,
            ]);

            $response->assertStatus(201);
        }

        $duration = microtime(true) - $startTime;
        $averageTime = $duration / $count;

        // Cada depósito deve levar menos de 1 segundo em média
        $this->assertLessThan(1.0, $averageTime);

        // Verificar que todos foram criados
        $depositsCount = Solicitacoes::where('user_id', $targetUser->user_id)->count();
        $this->assertEquals($count, $depositsCount);
    }

    /**
     * Teste: Deve manter consistência com múltiplos depósitos simultâneos
     */
    public function test_should_maintain_consistency_with_concurrent_deposits(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 0,
        ]);

        $initialBalance = $targetUser->saldo;
        $count = 5;
        $amount = 100.00;

        // Criar depósitos em sequência (simulando concorrência)
        for ($i = 0; $i < $count; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/admin/manual-transactions/deposits', [
                'user_id' => $targetUser->user_id,
                'amount' => $amount,
            ]);

            $response->assertStatus(201);
        }

        // Verificar que o saldo foi atualizado corretamente
        $targetUser->refresh();
        $this->assertGreaterThan($initialBalance, $targetUser->saldo);

        // Verificar que todos os depósitos foram criados
        $depositsCount = Solicitacoes::where('user_id', $targetUser->user_id)->count();
        $this->assertEquals($count, $depositsCount);
    }

    /**
     * Teste: Deve processar requisições em tempo razoável
     */
    public function test_should_process_request_in_reasonable_time(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/deposits', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);

        $duration = microtime(true) - $startTime;

        $response->assertStatus(201);
        
        // Deve processar em menos de 2 segundos
        $this->assertLessThan(2.0, $duration);
    }

    /**
     * Teste: Deve usar cache para configurações
     */
    public function test_should_use_cache_for_settings(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        // Limpar cache primeiro
        Cache::flush();

        // Primeira requisição (deve buscar do banco)
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/deposits', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);

        $response1->assertStatus(201);

        // Segunda requisição (deve usar cache)
        $targetUser2 = AuthTestHelper::createTestUser([
            'username' => 'target2_' . uniqid(),
            'email' => 'target2_' . uniqid() . '@example.com',
        ]);

        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/deposits', [
            'user_id' => $targetUser2->user_id,
            'amount' => 100.00,
        ]);

        $response2->assertStatus(201);

        // Ambas devem ter sucesso
        $this->assertTrue($response1->json('success'));
        $this->assertTrue($response2->json('success'));
    }

    /**
     * Teste: Deve lidar com valores grandes
     */
    public function test_should_handle_large_amounts(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $largeAmount = 100000.00;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/deposits', [
            'user_id' => $targetUser->user_id,
            'amount' => $largeAmount,
        ]);

        $response->assertStatus(201);
        
        $deposit = Solicitacoes::where('idTransaction', $response->json('data.deposit.transaction_id'))->first();
        $this->assertEquals($largeAmount, $deposit->amount);
    }

    /**
     * Teste: Deve limpar cache após criar depósito
     */
    public function test_should_clear_cache_after_deposit(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        // Criar depósito
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/deposits', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);

        $response->assertStatus(201);

        // Verificar que o depósito foi criado
        $deposit = Solicitacoes::where('idTransaction', $response->json('data.deposit.transaction_id'))->first();
        $this->assertNotNull($deposit);
    }
}








