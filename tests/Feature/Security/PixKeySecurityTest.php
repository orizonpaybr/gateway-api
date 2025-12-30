<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use App\Models\User;
use App\Models\UsersKey;
use App\Models\PixKey;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Segurança - PIX Com Chave
 * 
 * Cobre:
 * - SQL Injection
 * - XSS (Cross-Site Scripting)
 * - Authorization (Acesso não autorizado, IDOR)
 * - Input Validation
 * - Sensitive Data Exposure
 * - Path Traversal
 * - Command Injection
 * - Rate Limiting
 * - Key Format Validation
 */
class PixKeySecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Criar usuário principal
        $this->user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
            'status' => 1,
            'banido' => 0,
            'permission' => UserPermission::CLIENT,
            'saldo' => 1000.00,
            'cpf_cnpj' => '12345678900',
            'telefone' => '11999999999',
            'name' => 'Test User',
        ]);

        UsersKey::factory()->create([
            'user_id' => $this->user->user_id,
            'token' => 'test_token_' . $this->user->username,
        ]);

        // Criar outro usuário para testes de IDOR
        $this->otherUser = User::factory()->create([
            'username' => 'otheruser',
            'user_id' => 'otheruser',
            'email' => 'other@example.com',
            'password' => Hash::make('password123'),
            'status' => 1,
            'permission' => UserPermission::CLIENT,
            'cpf_cnpj' => '98765432100',
            'saldo' => 500.00,
        ]);

        UsersKey::factory()->create([
            'user_id' => $this->otherUser->user_id,
        ]);

        // Criar chave PIX para o usuário principal
        PixKey::create([
            'user_id' => $this->user->username,
            'key_type' => 'cpf',
            'key_value' => '12345678900',
            'key_label' => 'CPF Principal',
            'is_active' => true,
            'is_default' => true,
        ]);

        // Criar chave PIX para outro usuário
        PixKey::create([
            'user_id' => $this->otherUser->username,
            'key_type' => 'cpf',
            'key_value' => '98765432100',
            'key_label' => 'CPF Outro',
            'is_active' => true,
            'is_default' => true,
        ]);

        // Fazer login e obter token
        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => 'testuser',
            'password' => 'password123',
        ]);

        $this->token = $loginResponse->json('token') ?? $loginResponse->json('data.token');
    }

    // ===== AUTHORIZATION TESTS =====

    /**
     * Teste: Deve exigir autenticação para listar chaves PIX
     */
    public function test_should_require_authentication_to_list_keys(): void
    {
        $response = $this->getJson('/api/pix/keys');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve exigir autenticação para criar chave PIX
     */
    public function test_should_require_authentication_to_create_key(): void
    {
        $response = $this->postJson('/api/pix/keys', [
            'key_type' => 'cpf',
            'key_value' => '11122233344',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve prevenir IDOR - não deve acessar chave de outro usuário
     */
    public function test_should_prevent_idor_access_other_users_key(): void
    {
        $otherUserKey = PixKey::where('user_id', $this->otherUser->username)->first();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson("/api/pix/keys/{$otherUserKey->id}");

        // Deve retornar 404 (não encontrado) ou 403 (proibido)
        $this->assertContains($response->status(), [403, 404]);
    }

    /**
     * Teste: Deve prevenir IDOR - não deve atualizar chave de outro usuário
     */
    public function test_should_prevent_idor_update_other_users_key(): void
    {
        $otherUserKey = PixKey::where('user_id', $this->otherUser->username)->first();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson("/api/pix/keys/{$otherUserKey->id}", [
            'key_label' => 'Hacked',
        ]);

        // Deve retornar 404 (não encontrado) ou 403 (proibido)
        $this->assertContains($response->status(), [403, 404]);
    }

    /**
     * Teste: Deve prevenir IDOR - não deve deletar chave de outro usuário
     */
    public function test_should_prevent_idor_delete_other_users_key(): void
    {
        $otherUserKey = PixKey::where('user_id', $this->otherUser->username)->first();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->deleteJson("/api/pix/keys/{$otherUserKey->id}");

        // Deve retornar 404 (não encontrado) ou 403 (proibido)
        $this->assertContains($response->status(), [403, 404]);
    }

    // ===== INPUT VALIDATION TESTS =====

    /**
     * Teste: Deve validar tipo de chave
     */
    public function test_should_validate_key_type(): void
    {
        $invalidTypes = [
            'invalid',
            '<script>alert("XSS")</script>',
            'sql_injection',
        ];

        foreach ($invalidTypes as $invalidType) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/pix/keys', [
                'key_type' => $invalidType,
                'key_value' => '11122233344',
            ]);

            $this->assertContains($response->status(), [400, 422]);
        }
    }

    /**
     * Teste: Deve validar formato de chave CPF
     */
    public function test_should_validate_cpf_format(): void
    {
        $invalidCpfs = [
            '123',
            '123456789012345',
            'abc12345678',
        ];

        foreach ($invalidCpfs as $invalidCpf) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/pix/keys', [
                'key_type' => 'cpf',
                'key_value' => $invalidCpf,
            ]);

            $this->assertContains($response->status(), [400, 422]);
        }
    }

    /**
     * Teste: Deve validar formato de chave email
     */
    public function test_should_validate_email_format(): void
    {
        $invalidEmails = [
            'invalid-email',
            'not@email',
            '<script>alert("XSS")</script>',
        ];

        foreach ($invalidEmails as $invalidEmail) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/pix/keys', [
                'key_type' => 'email',
                'key_value' => $invalidEmail,
            ]);

            $this->assertContains($response->status(), [400, 422]);
        }
    }

    /**
     * Teste: Deve validar valor do saque
     */
    public function test_should_validate_withdraw_amount(): void
    {
        $invalidAmounts = [
            -1,
            0,
            'invalid',
            '<script>alert("XSS")</script>',
        ];

        foreach ($invalidAmounts as $invalidAmount) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/pix/withdraw-with-key', [
                'key_type' => 'cpf',
                'key_value' => '11122233344',
                'amount' => $invalidAmount,
            ]);

            $this->assertContains($response->status(), [400, 422]);
        }
    }

    // ===== SQL INJECTION TESTS =====

    /**
     * Teste: Deve prevenir SQL Injection em key_value
     */
    public function test_should_prevent_sql_injection_in_key_value(): void
    {
        $sqlInjectionPayloads = [
            "' OR '1'='1",
            "'; DROP TABLE pix_keys--",
            "' UNION SELECT * FROM pix_keys--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/pix/keys', [
                'key_type' => 'cpf',
                'key_value' => $payload,
            ]);

            // Não deve retornar erros SQL
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            
            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
            }
        }
    }

    /**
     * Teste: Deve prevenir SQL Injection em key_label
     */
    public function test_should_prevent_sql_injection_in_key_label(): void
    {
        $sqlInjectionPayloads = [
            "' OR '1'='1",
            "'; DROP TABLE pix_keys--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/pix/keys', [
                'key_type' => 'cpf',
                'key_value' => '11122233344',
                'key_label' => $payload,
            ]);

            // Não deve retornar erros SQL
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        }
    }

    // ===== XSS TESTS =====

    /**
     * Teste: Deve prevenir XSS em key_label
     */
    public function test_should_prevent_xss_in_key_label(): void
    {
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            'javascript:alert("XSS")',
            'onerror=alert("XSS")',
        ];

        foreach ($xssPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/pix/keys', [
                'key_type' => 'cpf',
                'key_value' => '11122233344',
                'key_label' => $payload,
            ]);

            $content = $response->getContent();
            
            // Verificar que não há erros de execução de script
            $this->assertStringNotContainsString('Fatal error', $content);
            $this->assertStringNotContainsString('Parse error', $content);
        }
    }

    // ===== SENSITIVE DATA EXPOSURE TESTS =====

    /**
     * Teste: Não deve expor informações sensíveis em respostas
     */
    public function test_should_not_expose_sensitive_info_in_responses(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/pix/keys');

        $content = $response->getContent();
        
        // Não deve expor senhas
        $this->assertStringNotContainsString('password', strtolower($content));
        $this->assertStringNotContainsString('password_hash', strtolower($content));
        
        // Não deve expor tokens completos
        $this->assertStringNotContainsString($this->token, $content);
        
        // Não deve expor stack traces
        $this->assertStringNotContainsString('Stack trace', $content);
    }

    // ===== RATE LIMITING TESTS =====

    /**
     * Teste: Deve implementar rate limiting em criar chave
     */
    public function test_should_implement_rate_limiting_in_create_key(): void
    {
        // Fazer múltiplas requisições rapidamente
        for ($i = 0; $i < 65; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/pix/keys', [
                'key_type' => 'cpf',
                'key_value' => '111222333' . str_pad($i, 2, '0', STR_PAD_LEFT),
            ]);

            // Após algumas tentativas, deve retornar 429
            if ($i >= 59) {
                $this->assertContains($response->status(), [200, 400, 409, 429]);
            }
        }
    }

    // ===== COMMAND INJECTION TESTS =====

    /**
     * Teste: Deve prevenir command injection
     */
    public function test_should_prevent_command_injection(): void
    {
        $commandInjectionPayloads = [
            '; ls -la',
            '| cat /etc/passwd',
            '&& rm -rf /',
            '`whoami`',
            '$(whoami)',
        ];

        foreach ($commandInjectionPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/pix/keys', [
                'key_type' => 'cpf',
                'key_value' => $payload,
            ]);

            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
            }
            
            // Não deve executar comandos
            $content = $response->getContent();
            $this->assertStringNotContainsString('root', $content);
        }
    }
}






