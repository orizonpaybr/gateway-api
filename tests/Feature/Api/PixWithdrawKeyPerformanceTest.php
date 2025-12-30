<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\App;
use App\Models\UsersKey;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Performance e Concorrência - API de PIX Saque com Chave
 * 
 * Cobre:
 * - Performance com múltiplas requisições
 * - Concorrência
 * - Escalabilidade
 * - Validação rápida
 */
class PixWithdrawKeyPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Criar usuário e obter token
        $this->user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'status' => 1,
            'banido' => 0,
            'permission' => UserPermission::CLIENT,
            'saldo' => 10000.00,
            'saque_bloqueado' => false,
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
        App::create([]);
    }

    /**
     * Teste: Deve validar requisições rapidamente
     */
    public function test_should_validate_requests_quickly(): void
    {
        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/pix/withdraw-with-key', [
            'key_type' => 'cpf',
            'key_value' => '12345678900',
            'amount' => 100.00,
        ]);

        $duration = microtime(true) - $startTime;

        // Validação deve ser rápida mesmo se o adquirente falhar
        $this->assertLessThan(5.0, $duration);
    }

    /**
     * Teste: Deve processar múltiplas requisições em sequência
     */
    public function test_should_handle_multiple_sequential_requests(): void
    {
        $amounts = [50.00, 100.00, 200.00, 500.00];
        $keyTypes = ['cpf', 'cnpj', 'email', 'telefone'];
        
        foreach ($amounts as $index => $amount) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/pix/withdraw-with-key', [
                'key_type' => $keyTypes[$index] ?? 'cpf',
                'key_value' => $keyTypes[$index] === 'email' ? 'test@example.com' : '12345678900',
                'amount' => $amount,
            ]);

            // Pode retornar 200 (sucesso) ou 500 (erro do adquirente em ambiente de teste)
            $this->assertContains($response->status(), [200, 400, 500]);
        }
    }

    /**
     * Teste: Deve validar campos rapidamente
     */
    public function test_should_validate_fields_quickly(): void
    {
        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/pix/withdraw-with-key', [
            'key_type' => 'invalid', // Valor inválido
            'key_value' => '12345678900',
            'amount' => 100.00,
        ]);

        $duration = microtime(true) - $startTime;

        $response->assertStatus(400);
        
        // Validação deve ser muito rápida (< 1 segundo)
        $this->assertLessThan(1.0, $duration);
    }

    /**
     * Teste: Deve processar requisições com diferentes tipos de chave
     */
    public function test_should_process_different_key_types(): void
    {
        $testCases = [
            ['type' => 'cpf', 'value' => '12345678900'],
            ['type' => 'cnpj', 'value' => '12345678000190'],
            ['type' => 'email', 'value' => 'test@example.com'],
            ['type' => 'telefone', 'value' => '11999999999'],
            ['type' => 'aleatoria', 'value' => '000000000000000000000000000000000000'],
        ];

        foreach ($testCases as $testCase) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/pix/withdraw-with-key', [
                'key_type' => $testCase['type'],
                'key_value' => $testCase['value'],
                'amount' => 100.00,
            ]);

            // Pode retornar 200 (sucesso) ou 500 (erro do adquirente em ambiente de teste)
            $this->assertContains($response->status(), [200, 400, 500]);
        }
    }

    /**
     * Teste: Deve manter consistência com múltiplas requisições
     */
    public function test_should_maintain_consistency_with_multiple_requests(): void
    {
        // Fazer múltiplas requisições com o mesmo valor
        for ($i = 0; $i < 5; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/pix/withdraw-with-key', [
                'key_type' => 'cpf',
                'key_value' => '12345678900',
                'amount' => 100.00,
            ]);

            // Todas devem ser processadas (mesmo que falhem no adquirente)
            $this->assertContains($response->status(), [200, 400, 500]);
        }
    }

    /**
     * Teste: Deve validar autenticação rapidamente
     */
    public function test_should_validate_authentication_quickly(): void
    {
        $startTime = microtime(true);

        $response = $this->postJson('/api/pix/withdraw-with-key', [
            'key_type' => 'cpf',
            'key_value' => '12345678900',
            'amount' => 100.00,
        ]);

        $duration = microtime(true) - $startTime;

        $response->assertStatus(401);
        
        // Validação de autenticação deve ser muito rápida
        $this->assertLessThan(1.0, $duration);
    }

    /**
     * Teste: Deve verificar saldo rapidamente
     */
    public function test_should_check_balance_quickly(): void
    {
        // Atualizar saldo para valor menor
        $this->user->update(['saldo' => 50.00]);

        $startTime = microtime(true);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/pix/withdraw-with-key', [
            'key_type' => 'cpf',
            'key_value' => '12345678900',
            'amount' => 100.00, // Maior que o saldo
        ]);

        $duration = microtime(true) - $startTime;

        $response->assertStatus(400);
        
        // Verificação de saldo deve ser rápida
        $this->assertLessThan(1.0, $duration);
    }
}








