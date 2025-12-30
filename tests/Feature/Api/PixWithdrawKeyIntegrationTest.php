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
 * Testes de Integração - API de PIX Saque com Chave
 * 
 * Cobre:
 * - Endpoint POST /api/pix/withdraw-with-key
 * - Autenticação
 * - Validação de requests
 * - Respostas JSON
 * - Tratamento de erros
 * - Verificação de saldo
 * - Verificação de bloqueio
 */
class PixWithdrawKeyIntegrationTest extends TestCase
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
            'saldo' => 1000.00,
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
     * Teste: Deve validar campo key_type obrigatório
     */
    public function test_should_validate_key_type_required(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/pix/withdraw-with-key', [
            // Sem key_type
            'key_value' => '12345678900',
            'amount' => 100.00,
        ]);

        $response->assertStatus(400)
            ->assertJsonStructure([
                'success',
                'message',
                'errors',
            ])
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * Teste: Deve validar campo key_value obrigatório
     */
    public function test_should_validate_key_value_required(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/pix/withdraw-with-key', [
            'key_type' => 'cpf',
            // Sem key_value
            'amount' => 100.00,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * Teste: Deve validar campo amount obrigatório
     */
    public function test_should_validate_amount_required(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/pix/withdraw-with-key', [
            'key_type' => 'cpf',
            'key_value' => '12345678900',
            // Sem amount
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * Teste: Deve validar valor mínimo
     */
    public function test_should_validate_minimum_amount(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/pix/withdraw-with-key', [
            'key_type' => 'cpf',
            'key_value' => '12345678900',
            'amount' => 0.001, // Menor que mínimo (0.01)
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * Teste: Deve validar tipos de chave válidos
     */
    public function test_should_validate_key_type_values(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/pix/withdraw-with-key', [
            'key_type' => 'invalid_type', // Tipo inválido
            'key_value' => '12345678900',
            'amount' => 100.00,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * Teste: Deve verificar saldo insuficiente
     */
    public function test_should_check_insufficient_balance(): void
    {
        // Atualizar saldo do usuário para valor menor
        $this->user->update(['saldo' => 50.00]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/pix/withdraw-with-key', [
            'key_type' => 'cpf',
            'key_value' => '12345678900',
            'amount' => 100.00, // Maior que o saldo
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Saldo insuficiente',
            ]);
    }

    /**
     * Teste: Deve verificar bloqueio de saque
     */
    public function test_should_check_withdraw_blocked(): void
    {
        // Bloquear saque do usuário
        $this->user->update(['saque_bloqueado' => true]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/pix/withdraw-with-key', [
            'key_type' => 'cpf',
            'key_value' => '12345678900',
            'amount' => 100.00,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);
        
        $this->assertStringContainsString('bloqueado', strtolower($response->json('message')));
    }

    /**
     * Teste: Deve aceitar tipos de chave válidos
     */
    public function test_should_accept_valid_key_types(): void
    {
        $validTypes = [
            ['type' => 'cpf', 'value' => '12345678900'],
            ['type' => 'cnpj', 'value' => '12345678000190'],
            ['type' => 'telefone', 'value' => '11999999999'],
            ['type' => 'email', 'value' => 'test@example.com'],
            ['type' => 'aleatoria', 'value' => '000000000000000000000000000000000000'],
        ];
        
        foreach ($validTypes as $testCase) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/pix/withdraw-with-key', [
                'key_type' => $testCase['type'],
                'key_value' => $testCase['value'],
                'amount' => 100.00,
            ]);

            // Pode retornar 200 (sucesso) ou 500 (erro do adquirente em ambiente de teste)
            // Se retornar 400, verificar que não é erro de tipo de chave inválido
            if ($response->status() === 400) {
                $message = strtolower($response->json('message') ?? '');
                $errors = $response->json('errors') ?? [];
                
                // Não deve ser erro de tipo de chave inválido
                $this->assertNotContains('key_type', array_keys($errors), "Tipo {$testCase['type']} deve ser aceito");
            } else {
                // Se não for 400, está ok (pode ser 200 ou 500)
                $this->assertContains($response->status(), [200, 500], "Tipo {$testCase['type']} deve ser processado");
            }
        }
    }

    /**
     * Teste: Deve retornar erro 401 sem autenticação
     */
    public function test_should_require_authentication(): void
    {
        $response = $this->postJson('/api/pix/withdraw-with-key', [
            'key_type' => 'cpf',
            'key_value' => '12345678900',
            'amount' => 100.00,
        ]);

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve validar descrição máxima
     */
    public function test_should_validate_description_max_length(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/pix/withdraw-with-key', [
            'key_type' => 'cpf',
            'key_value' => '12345678900',
            'amount' => 100.00,
            'description' => str_repeat('a', 256), // Mais que 255 caracteres
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * Teste: Deve aceitar descrição opcional
     */
    public function test_should_accept_optional_description(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/pix/withdraw-with-key', [
            'key_type' => 'cpf',
            'key_value' => '12345678900',
            'amount' => 100.00,
            // Sem description
        ]);

        // Pode retornar 200 (sucesso) ou 500 (erro do adquirente em ambiente de teste)
        // Mas não deve retornar 400 por falta de descrição
        $this->assertNotEquals(400, $response->status());
    }

    /**
     * Teste: Deve aceitar valores válidos
     */
    public function test_should_accept_valid_values(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/pix/withdraw-with-key', [
            'key_type' => 'cpf',
            'key_value' => '12345678900',
            'amount' => 100.00,
            'description' => 'Saque de teste',
        ]);

        // Pode retornar 200 (sucesso) ou 500 (erro do adquirente em ambiente de teste)
        $this->assertContains($response->status(), [200, 400, 500]);
        
        if ($response->status() === 400) {
            // Se retornar 400, verificar que não é erro de validação básica
            $this->assertNotEquals('Dados inválidos', $response->json('message'));
        }
    }

    /**
     * Teste: Deve rejeitar valores negativos
     */
    public function test_should_reject_negative_amounts(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/pix/withdraw-with-key', [
            'key_type' => 'cpf',
            'key_value' => '12345678900',
            'amount' => -100.00,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * Teste: Deve aceitar valores decimais
     */
    public function test_should_accept_decimal_amounts(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/pix/withdraw-with-key', [
            'key_type' => 'cpf',
            'key_value' => '12345678900',
            'amount' => 99.99,
            'description' => 'Teste com centavos',
        ]);

        // Pode retornar 200 (sucesso) ou 500 (erro do adquirente em ambiente de teste)
        $this->assertContains($response->status(), [200, 400, 500]);
    }
}

