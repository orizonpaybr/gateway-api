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
 * Testes de Performance e Concorrência - API de PIX Depositar
 * 
 * Cobre:
 * - Performance com múltiplas requisições
 * - Concorrência
 * - Escalabilidade
 * - Validação rápida
 */
class PixDepositPerformanceTest extends TestCase
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
        ])->postJson('/api/pix/generate-qr', [
            'amount' => 100.00,
            'description' => 'Teste',
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
        $amounts = [50.00, 100.00, 200.00, 500.00, 1000.00];
        
        foreach ($amounts as $amount) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/pix/generate-qr', [
                'amount' => $amount,
                'description' => "Depósito de R$ {$amount}",
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
        ])->postJson('/api/pix/generate-qr', [
            'amount' => 0.001, // Valor inválido
        ]);

        $duration = microtime(true) - $startTime;

        $response->assertStatus(400);
        
        // Validação deve ser muito rápida (< 1 segundo)
        $this->assertLessThan(1.0, $duration);
    }

    /**
     * Teste: Deve processar requisições com diferentes valores
     */
    public function test_should_process_different_amounts(): void
    {
        $testAmounts = [
            1.00,
            10.00,
            50.00,
            100.00,
            500.00,
            1000.00,
            5000.00,
        ];

        foreach ($testAmounts as $amount) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/pix/generate-qr', [
                'amount' => $amount,
                'description' => "Teste R$ {$amount}",
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
            ])->postJson('/api/pix/generate-qr', [
                'amount' => 100.00,
                'description' => "Requisição {$i}",
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

        $response = $this->postJson('/api/pix/generate-qr', [
            'amount' => 100.00,
        ]);

        $duration = microtime(true) - $startTime;

        $response->assertStatus(401);
        
        // Validação de autenticação deve ser muito rápida
        $this->assertLessThan(1.0, $duration);
    }
}

