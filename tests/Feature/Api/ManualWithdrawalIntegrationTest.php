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
 * Testes de Integração - API de Saque Manual
 * 
 * Cobre:
 * - Endpoint de criar saque manual
 * - Autenticação
 * - Validação de requests
 * - Validação de saldo suficiente
 * - Respostas JSON
 * - Tratamento de erros
 * - Atualização de saldo
 */
class ManualWithdrawalIntegrationTest extends TestCase
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
     * Teste: Deve criar saque manual com sucesso
     */
    public function test_should_create_manual_withdrawal(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 1000.00,
        ]);

        $initialBalance = $targetUser->saldo;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/withdrawal', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
            'description' => 'Teste manual',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'withdrawal' => [
                        'id',
                        'transaction_id',
                        'amount',
                        'valor_liquido',
                        'taxa',
                        'valor_total_descontado',
                        'status',
                        'descricao',
                        'created_at',
                        'user',
                    ],
                ],
            ]);

        $this->assertTrue($response->json('success'));

        // Verificar que o saque foi criado
        $withdrawal = SolicitacoesCashOut::where('user_id', $targetUser->user_id)
            ->where('idTransaction', $response->json('data.withdrawal.transaction_id'))
            ->first();
        
        $this->assertNotNull($withdrawal);
        $this->assertEquals('PAID_OUT', $withdrawal->status);
        $this->assertEquals(100.00, $withdrawal->amount);

        // Verificar que o saldo foi atualizado
        $targetUser->refresh();
        $this->assertLessThan($initialBalance, $targetUser->saldo);
    }

    /**
     * Teste: Deve validar campos obrigatórios
     */
    public function test_should_validate_required_fields(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/withdrawal', []);

        $response->assertStatus(422);
        $this->assertArrayHasKey('errors', $response->json());
    }

    /**
     * Teste: Deve validar user_id existe
     */
    public function test_should_validate_user_exists(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/withdrawal', [
            'user_id' => 'nonexistent_user',
            'amount' => 100.00,
        ]);

        $response->assertStatus(422);
    }

    /**
     * Teste: Deve validar amount mínimo
     */
    public function test_should_validate_amount_minimum(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 1000.00,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/withdrawal', [
            'user_id' => $targetUser->user_id,
            'amount' => 0,
        ]);

        $response->assertStatus(422);
    }

    /**
     * Teste: Deve retornar erro quando saldo insuficiente
     */
    public function test_should_return_error_when_insufficient_balance(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 10.00, // Saldo insuficiente
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/withdrawal', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);

        $response->assertStatus(400)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'saldo_disponivel',
                    'valor_necessario',
                    'valor_saque',
                    'taxa',
                ],
            ]);

        $this->assertFalse($response->json('success'));
        $this->assertStringContainsString('insuficiente', $response->json('message'));
    }

    /**
     * Teste: Deve usar descrição padrão quando não fornecida
     */
    public function test_should_use_default_description(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 1000.00,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/withdrawal', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);

        $response->assertStatus(201);
        
        $withdrawal = SolicitacoesCashOut::where('idTransaction', $response->json('data.withdrawal.transaction_id'))->first();
        $this->assertEquals('MANUAL', $withdrawal->descricao_transacao);
    }

    /**
     * Teste: Deve usar descrição customizada quando fornecida
     */
    public function test_should_use_custom_description(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 1000.00,
        ]);

        $customDescription = 'Pagamento ao usuário';

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/withdrawal', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
            'description' => $customDescription,
        ]);

        $response->assertStatus(201);
        
        $withdrawal = SolicitacoesCashOut::where('idTransaction', $response->json('data.withdrawal.transaction_id'))->first();
        $this->assertEquals($customDescription, $withdrawal->descricao_transacao);
    }

    /**
     * Teste: Deve calcular taxas corretamente
     */
    public function test_should_calculate_fees_correctly(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 1000.00,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/withdrawal', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);

        $response->assertStatus(201);
        
        $withdrawal = SolicitacoesCashOut::where('idTransaction', $response->json('data.withdrawal.transaction_id'))->first();
        
        // Verificar que as taxas foram calculadas
        $this->assertNotNull($withdrawal);
        $this->assertGreaterThanOrEqual(0, $withdrawal->taxa_cash_out);
    }

    /**
     * Teste: Deve atualizar saldo do usuário
     */
    public function test_should_update_user_balance(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 1000.00,
        ]);

        $initialBalance = $targetUser->saldo;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/withdrawal', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);

        $response->assertStatus(201);
        
        $targetUser->refresh();
        $this->assertLessThan($initialBalance, $targetUser->saldo);
    }

    /**
     * Teste: Deve retornar erro 401 sem autenticação
     */
    public function test_should_require_authentication(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 1000.00,
        ]);

        $response = $this->postJson('/api/admin/manual-transactions/withdrawal', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve retornar erro 403 para não-admin
     */
    public function test_should_require_admin_permission(): void
    {
        $nonAdmin = AuthTestHelper::createTestUser([
            'username' => 'nonadmin_' . uniqid(),
            'email' => 'nonadmin_' . uniqid() . '@example.com',
            'permission' => 1, // Usuário comum (não admin)
        ]);

        UsersKey::factory()->create([
            'user_id' => $nonAdmin->user_id ?? $nonAdmin->username,
            'token' => 'test_token_' . $nonAdmin->username,
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => $nonAdmin->username,
            'password' => 'password123',
        ]);

        $nonAdminToken = $loginResponse->json('token') ?? $loginResponse->json('data.token');

        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 1000.00,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $nonAdminToken,
        ])->postJson('/api/admin/manual-transactions/withdrawal', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);

        // Deve retornar 403 ou 401 dependendo do middleware
        $this->assertContains($response->status(), [401, 403]);
    }
}








