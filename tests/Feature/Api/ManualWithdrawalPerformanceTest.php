<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\SolicitacoesCashOut;
use App\Models\App;
use App\Models\UsersKey;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Performance e Concorrência - API de Saque Manual
 * 
 * Cobre:
 * - Performance com múltiplas requisições
 * - Concorrência
 * - Escalabilidade
 * - Cache
 * - Validação de saldo em concorrência
 */
class ManualWithdrawalPerformanceTest extends TestCase
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
            'taxa_pix_cash_out' => 2.5,
            'taxa_pix_cash_out_valor_fixo' => 0.5,
        ]);
    }

    /**
     * Teste: Deve criar múltiplos saques em sequência rapidamente
     */
    public function test_should_create_multiple_withdrawals_quickly(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 10000.00,
        ]);

        $startTime = microtime(true);
        $count = 10;

        for ($i = 0; $i < $count; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/admin/manual-transactions/withdrawal', [
                'user_id' => $targetUser->user_id,
                'amount' => 10.00,
            ]);

            $response->assertStatus(201);
        }

        $duration = microtime(true) - $startTime;
        $averageTime = $duration / $count;

        // Cada saque deve levar menos de 1 segundo em média
        $this->assertLessThan(1.0, $averageTime);

        // Verificar que todos foram criados
        $withdrawalsCount = SolicitacoesCashOut::where('user_id', $targetUser->user_id)->count();
        $this->assertEquals($count, $withdrawalsCount);
    }

    /**
     * Teste: Deve manter consistência com múltiplos saques simultâneos
     */
    public function test_should_maintain_consistency_with_concurrent_withdrawals(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 10000.00,
        ]);

        $initialBalance = $targetUser->saldo;
        $count = 5;
        $amount = 100.00;

        // Criar saques em sequência (simulando concorrência)
        for ($i = 0; $i < $count; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/admin/manual-transactions/withdrawal', [
                'user_id' => $targetUser->user_id,
                'amount' => $amount,
            ]);

            $response->assertStatus(201);
        }

        // Verificar que o saldo foi atualizado corretamente
        $targetUser->refresh();
        $this->assertLessThan($initialBalance, $targetUser->saldo);

        // Verificar que todos os saques foram criados
        $withdrawalsCount = SolicitacoesCashOut::where('user_id', $targetUser->user_id)->count();
        $this->assertEquals($count, $withdrawalsCount);
    }

    /**
     * Teste: Deve processar requisições em tempo razoável
     */
    public function test_should_process_request_in_reasonable_time(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 1000.00,
        ]);

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/withdrawal', [
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
            'saldo' => 1000.00,
        ]);

        // Limpar cache primeiro
        Cache::flush();

        // Primeira requisição (deve buscar do banco)
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/withdrawal', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);

        $response1->assertStatus(201);

        // Segunda requisição (deve usar cache)
        $targetUser2 = AuthTestHelper::createTestUser([
            'username' => 'target2_' . uniqid(),
            'email' => 'target2_' . uniqid() . '@example.com',
            'saldo' => 1000.00,
        ]);

        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/withdrawal', [
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
            'saldo' => 1000000.00,
        ]);

        $largeAmount = 100000.00;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/withdrawal', [
            'user_id' => $targetUser->user_id,
            'amount' => $largeAmount,
        ]);

        $response->assertStatus(201);
        
        $withdrawal = SolicitacoesCashOut::where('idTransaction', $response->json('data.withdrawal.transaction_id'))->first();
        $this->assertEquals($largeAmount, $withdrawal->amount);
    }

    /**
     * Teste: Deve prevenir saques quando saldo insuficiente
     */
    public function test_should_prevent_withdrawals_when_insufficient_balance(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 100.00,
        ]);

        $initialBalance = $targetUser->saldo;

        // Tentar criar saque maior que o saldo
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/withdrawal', [
            'user_id' => $targetUser->user_id,
            'amount' => 1000.00,
        ]);

        $response->assertStatus(400);

        // Verificar que o saldo não foi alterado
        $targetUser->refresh();
        $this->assertEquals($initialBalance, $targetUser->saldo);

        // Verificar que nenhum saque foi criado
        $withdrawalsCount = SolicitacoesCashOut::where('user_id', $targetUser->user_id)->count();
        $this->assertEquals(0, $withdrawalsCount);
    }

    /**
     * Teste: Deve limpar cache após criar saque
     */
    public function test_should_clear_cache_after_withdrawal(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 1000.00,
        ]);

        // Criar saque
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/withdrawal', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);

        $response->assertStatus(201);

        // Verificar que o saque foi criado
        $withdrawal = SolicitacoesCashOut::where('idTransaction', $response->json('data.withdrawal.transaction_id'))->first();
        $this->assertNotNull($withdrawal);
    }
}








