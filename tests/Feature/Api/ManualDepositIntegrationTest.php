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
 * Testes de Integração - API de Depósito Manual
 * 
 * Cobre:
 * - Endpoint de criar depósito manual
 * - Autenticação
 * - Validação de requests
 * - Respostas JSON
 * - Tratamento de erros
 * - Atualização de saldo
 */
class ManualDepositIntegrationTest extends TestCase
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
     * Teste: Deve criar depósito manual com sucesso
     */
    public function test_should_create_manual_deposit(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 0,
        ]);

        $initialBalance = $targetUser->saldo;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/deposits', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
            'description' => 'Teste manual',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'deposit' => [
                        'id',
                        'transaction_id',
                        'amount',
                        'valor_liquido',
                        'taxa',
                        'status',
                        'descricao',
                        'created_at',
                        'user',
                    ],
                ],
            ]);

        $this->assertTrue($response->json('success'));

        // Verificar que o depósito foi criado
        $deposit = Solicitacoes::where('user_id', $targetUser->user_id)
            ->where('idTransaction', $response->json('data.deposit.transaction_id'))
            ->first();
        
        $this->assertNotNull($deposit);
        $this->assertEquals('PAID_OUT', $deposit->status);
        $this->assertEquals(100.00, $deposit->amount);

        // Verificar que o saldo foi atualizado
        $targetUser->refresh();
        $this->assertGreaterThan($initialBalance, $targetUser->saldo);
    }

    /**
     * Teste: Deve validar campos obrigatórios
     */
    public function test_should_validate_required_fields(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/deposits', []);

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
        ])->postJson('/api/admin/manual-transactions/deposits', [
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
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/deposits', [
            'user_id' => $targetUser->user_id,
            'amount' => 0,
        ]);

        $response->assertStatus(422);
    }

    /**
     * Teste: Deve usar descrição padrão quando não fornecida
     */
    public function test_should_use_default_description(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/deposits', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);

        $response->assertStatus(201);
        
        $deposit = Solicitacoes::where('idTransaction', $response->json('data.deposit.transaction_id'))->first();
        $this->assertEquals('MANUAL', $deposit->descricao_transacao);
    }

    /**
     * Teste: Deve usar descrição customizada quando fornecida
     */
    public function test_should_use_custom_description(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $customDescription = 'Bônus de performance';

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/deposits', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
            'description' => $customDescription,
        ]);

        $response->assertStatus(201);
        
        $deposit = Solicitacoes::where('idTransaction', $response->json('data.deposit.transaction_id'))->first();
        $this->assertEquals($customDescription, $deposit->descricao_transacao);
    }

    /**
     * Teste: Deve calcular taxas corretamente
     */
    public function test_should_calculate_fees_correctly(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/deposits', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);

        $response->assertStatus(201);
        
        $deposit = Solicitacoes::where('idTransaction', $response->json('data.deposit.transaction_id'))->first();
        
        // Verificar que as taxas foram calculadas
        $this->assertNotNull($deposit);
        $this->assertLessThan($deposit->amount, $deposit->deposito_liquido);
        $this->assertGreaterThan(0, $deposit->taxa_cash_in);
    }

    /**
     * Teste: Deve atualizar saldo do usuário
     */
    public function test_should_update_user_balance(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
            'saldo' => 50.00,
        ]);

        $initialBalance = $targetUser->saldo;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/admin/manual-transactions/deposits', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);

        $response->assertStatus(201);
        
        $targetUser->refresh();
        $this->assertGreaterThan($initialBalance, $targetUser->saldo);
    }

    /**
     * Teste: Deve retornar erro 401 sem autenticação
     */
    public function test_should_require_authentication(): void
    {
        $targetUser = AuthTestHelper::createTestUser([
            'username' => 'target_' . uniqid(),
            'email' => 'target_' . uniqid() . '@example.com',
        ]);

        $response = $this->postJson('/api/admin/manual-transactions/deposits', [
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
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $nonAdminToken,
        ])->postJson('/api/admin/manual-transactions/deposits', [
            'user_id' => $targetUser->user_id,
            'amount' => 100.00,
        ]);

        // Deve retornar 403 ou 401 dependendo do middleware
        $this->assertContains($response->status(), [401, 403]);
    }
}

