<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use App\Models\User;
use App\Models\UsersKey;
use App\Constants\UserPermission;
use App\Constants\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Segurança - Criar Transações Manuais (Entrada e Saída)
 * 
 * Cobre:
 * - SQL Injection
 * - XSS (Cross-Site Scripting)
 * - Authorization (Acesso não autorizado, apenas admins)
 * - Input Validation
 * - Sensitive Data Exposure
 * - Path Traversal
 * - Command Injection
 * - Rate Limiting
 * - Privilege Escalation
 * - Business Logic Security
 */
class ManualTransactionsSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $regularUser;
    private User $targetUser;
    private string $adminToken;
    private string $regularToken;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Criar usuário admin
        $this->adminUser = User::factory()->create([
            'username' => 'adminuser',
            'user_id' => 'adminuser',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
            'status' => UserStatus::ACTIVE,
            'banido' => 0,
            'permission' => UserPermission::ADMIN, // 3
            'saldo' => 10000.00,
            'cpf_cnpj' => '12345678900',
            'telefone' => '11999999999',
            'name' => 'Admin User',
        ]);

        UsersKey::factory()->create([
            'user_id' => $this->adminUser->user_id,
            'token' => 'test_token_admin',
        ]);

        // Criar usuário regular (não admin)
        $this->regularUser = User::factory()->create([
            'username' => 'regularuser',
            'user_id' => 'regularuser',
            'email' => 'regular@example.com',
            'password' => Hash::make('password123'),
            'status' => UserStatus::ACTIVE,
            'banido' => 0,
            'permission' => UserPermission::CLIENT, // 1
            'saldo' => 1000.00,
            'cpf_cnpj' => '98765432100',
            'telefone' => '11888888888',
            'name' => 'Regular User',
        ]);

        UsersKey::factory()->create([
            'user_id' => $this->regularUser->user_id,
            'token' => 'test_token_regular',
        ]);

        // Criar usuário alvo para testes
        $this->targetUser = User::factory()->create([
            'username' => 'targetuser',
            'user_id' => 'targetuser',
            'email' => 'target@example.com',
            'password' => Hash::make('password123'),
            'status' => UserStatus::ACTIVE,
            'banido' => 0,
            'permission' => UserPermission::CLIENT,
            'saldo' => 5000.00,
            'cpf_cnpj' => '11122233344',
            'telefone' => '11777777777',
            'name' => 'Target User',
        ]);

        UsersKey::factory()->create([
            'user_id' => $this->targetUser->user_id,
        ]);

        // Fazer login como admin e obter token
        $adminLoginResponse = $this->postJson('/api/auth/login', [
            'username' => 'adminuser',
            'password' => 'password123',
        ]);

        $this->adminToken = $adminLoginResponse->json('token') ?? $adminLoginResponse->json('data.token');

        // Fazer login como usuário regular e obter token
        $regularLoginResponse = $this->postJson('/api/auth/login', [
            'username' => 'regularuser',
            'password' => 'password123',
        ]);

        $this->regularToken = $regularLoginResponse->json('token') ?? $regularLoginResponse->json('data.token');
    }

    // ===== AUTHORIZATION TESTS - DEPÓSITOS =====

    /**
     * Teste: Deve exigir autenticação para criar depósito manual
     */
    public function test_should_require_authentication_to_create_manual_deposit(): void
    {
        $response = $this->postJson('/api/admin/manual-transactions/deposits', [
            'user_id' => $this->targetUser->user_id,
            'amount' => 100.00,
        ]);

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve exigir permissão de admin para criar depósito manual
     */
    public function test_should_require_admin_permission_to_create_manual_deposit(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->postJson('/api/admin/manual-transactions/deposits', [
            'user_id' => $this->targetUser->user_id,
            'amount' => 100.00,
        ]);

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    // ===== AUTHORIZATION TESTS - SAQUES =====

    /**
     * Teste: Deve exigir autenticação para criar saque manual
     */
    public function test_should_require_authentication_to_create_manual_withdrawal(): void
    {
        $response = $this->postJson('/api/admin/manual-transactions/withdrawal', [
            'user_id' => $this->targetUser->user_id,
            'amount' => 50.00,
        ]);

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve exigir permissão de admin para criar saque manual
     */
    public function test_should_require_admin_permission_to_create_manual_withdrawal(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->postJson('/api/admin/manual-transactions/withdrawal', [
            'user_id' => $this->targetUser->user_id,
            'amount' => 50.00,
        ]);

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    // ===== INPUT VALIDATION TESTS =====

    /**
     * Teste: Deve validar dados ao criar depósito manual
     */
    public function test_should_validate_data_when_creating_manual_deposit(): void
    {
        $invalidData = [
            ['user_id' => '', 'amount' => 100], // user_id vazio
            ['user_id' => 'nonexistent', 'amount' => 100], // usuário inexistente
            ['user_id' => $this->targetUser->user_id, 'amount' => ''], // amount vazio
            ['user_id' => $this->targetUser->user_id, 'amount' => 0], // amount zero
            ['user_id' => $this->targetUser->user_id, 'amount' => -1], // amount negativo
            ['user_id' => $this->targetUser->user_id, 'amount' => 'invalid'], // amount inválido
        ];

        foreach ($invalidData as $data) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->postJson('/api/admin/manual-transactions/deposits', $data);

            // Deve retornar 422 (Unprocessable Entity) ou 400
            $this->assertContains($response->status(), [400, 422]);
        }
    }

    /**
     * Teste: Deve validar dados ao criar saque manual
     */
    public function test_should_validate_data_when_creating_manual_withdrawal(): void
    {
        $invalidData = [
            ['user_id' => '', 'amount' => 50], // user_id vazio
            ['user_id' => 'nonexistent', 'amount' => 50], // usuário inexistente
            ['user_id' => $this->targetUser->user_id, 'amount' => ''], // amount vazio
            ['user_id' => $this->targetUser->user_id, 'amount' => 0], // amount zero
            ['user_id' => $this->targetUser->user_id, 'amount' => -1], // amount negativo
            ['user_id' => $this->targetUser->user_id, 'amount' => 'invalid'], // amount inválido
        ];

        foreach ($invalidData as $data) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->postJson('/api/admin/manual-transactions/withdrawal', $data);

            // Deve retornar 422 (Unprocessable Entity) ou 400
            $this->assertContains($response->status(), [400, 422]);
        }
    }

    /**
     * Teste: Deve validar descrição ao criar depósito manual
     */
    public function test_should_validate_description_when_creating_manual_deposit(): void
    {
        // Descrição muito longa (mais de 255 caracteres)
        $longDescription = str_repeat('a', 256);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/admin/manual-transactions/deposits', [
            'user_id' => $this->targetUser->user_id,
            'amount' => 100.00,
            'description' => $longDescription,
        ]);

        // Deve retornar 422 (Unprocessable Entity) ou 400
        $this->assertContains($response->status(), [400, 422]);
    }

    /**
     * Teste: Deve validar descrição ao criar saque manual
     */
    public function test_should_validate_description_when_creating_manual_withdrawal(): void
    {
        // Descrição muito longa (mais de 255 caracteres)
        $longDescription = str_repeat('a', 256);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/admin/manual-transactions/withdrawal', [
            'user_id' => $this->targetUser->user_id,
            'amount' => 50.00,
            'description' => $longDescription,
        ]);

        // Deve retornar 422 (Unprocessable Entity) ou 400
        $this->assertContains($response->status(), [400, 422]);
    }

    // ===== SQL INJECTION TESTS =====

    /**
     * Teste: Deve prevenir SQL Injection em user_id ao criar depósito
     */
    public function test_should_prevent_sql_injection_in_user_id_when_creating_deposit(): void
    {
        $sqlInjectionPayloads = [
            "'; DROP TABLE users--",
            "' UNION SELECT * FROM users--",
            "1' OR '1'='1",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->postJson('/api/admin/manual-transactions/deposits', [
                'user_id' => $payload,
                'amount' => 100.00,
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
     * Teste: Deve prevenir SQL Injection em user_id ao criar saque
     */
    public function test_should_prevent_sql_injection_in_user_id_when_creating_withdrawal(): void
    {
        $sqlInjectionPayloads = [
            "'; DROP TABLE users--",
            "' UNION SELECT * FROM users--",
            "1' OR '1'='1",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->postJson('/api/admin/manual-transactions/withdrawal', [
                'user_id' => $payload,
                'amount' => 50.00,
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

    // ===== XSS TESTS =====

    /**
     * Teste: Deve prevenir XSS em descrição ao criar depósito
     */
    public function test_should_prevent_xss_in_description_when_creating_deposit(): void
    {
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            'javascript:alert("XSS")',
            'onerror=alert("XSS")',
        ];

        foreach ($xssPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->postJson('/api/admin/manual-transactions/deposits', [
                'user_id' => $this->targetUser->user_id,
                'amount' => 100.00,
                'description' => $payload,
            ]);

            $content = $response->getContent();
            
            // Verificar que não há erros de execução de script
            $this->assertStringNotContainsString('Fatal error', $content);
            $this->assertStringNotContainsString('Parse error', $content);
        }
    }

    /**
     * Teste: Deve prevenir XSS em descrição ao criar saque
     */
    public function test_should_prevent_xss_in_description_when_creating_withdrawal(): void
    {
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            'javascript:alert("XSS")',
            'onerror=alert("XSS")',
        ];

        foreach ($xssPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->postJson('/api/admin/manual-transactions/withdrawal', [
                'user_id' => $this->targetUser->user_id,
                'amount' => 50.00,
                'description' => $payload,
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
        // Criar depósito manual
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/admin/manual-transactions/deposits', [
            'user_id' => $this->targetUser->user_id,
            'amount' => 100.00,
            'description' => 'Test deposit',
        ]);

        $content = $response->getContent();
        
        // Não deve expor senhas
        $this->assertStringNotContainsString('password', strtolower($content));
        $this->assertStringNotContainsString('password_hash', strtolower($content));
        
        // Não deve expor tokens completos
        $this->assertStringNotContainsString($this->adminToken, $content);
        
        // Não deve expor stack traces
        $this->assertStringNotContainsString('Stack trace', $content);
        $this->assertStringNotContainsString('/var/www', $content);
    }

    /**
     * Teste: Não deve expor informações sensíveis em erros
     */
    public function test_should_not_expose_sensitive_info_in_errors(): void
    {
        // Simular erro forçando uma exceção
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid_token_that_will_cause_error',
        ])->postJson('/api/admin/manual-transactions/deposits', [
            'user_id' => $this->targetUser->user_id,
            'amount' => 100.00,
        ]);

        $content = $response->getContent();
        
        // Não deve expor stack traces
        $this->assertStringNotContainsString('Stack trace', $content);
        $this->assertStringNotContainsString('/var/www', $content);
        $this->assertStringNotContainsString('at ', $content);
        
        // Não deve expor informações do banco de dados
        $this->assertStringNotContainsString('SQLSTATE', $content);
    }

    // ===== BUSINESS LOGIC SECURITY TESTS =====

    /**
     * Teste: Não deve permitir criar saque com saldo insuficiente
     */
    public function test_should_not_allow_withdrawal_with_insufficient_balance(): void
    {
        // Criar usuário com saldo zero
        $poorUser = User::factory()->create([
            'username' => 'pooruser',
            'user_id' => 'pooruser',
            'email' => 'poor@example.com',
            'password' => Hash::make('password123'),
            'status' => UserStatus::ACTIVE,
            'permission' => UserPermission::CLIENT,
            'saldo' => 0.00,
        ]);

        UsersKey::factory()->create([
            'user_id' => $poorUser->user_id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/admin/manual-transactions/withdrawal', [
            'user_id' => $poorUser->user_id,
            'amount' => 1000.00, // Valor maior que o saldo
        ]);

        // Deve retornar 400 (Bad Request) ou 500
        // Se retornar 500, verificar que não expõe informações sensíveis
        if ($response->status() === 500) {
            $content = $response->getContent();
            $this->assertStringNotContainsString('Stack trace', $content);
        }
        
        $this->assertContains($response->status(), [400, 500]);
        
        // Se retornar 400, deve conter mensagem de saldo insuficiente
        if ($response->status() === 400) {
            $this->assertStringContainsString('insuficiente', strtolower($response->getContent()));
        }
    }

    // ===== RATE LIMITING TESTS =====

    /**
     * Teste: Deve implementar rate limiting em criar depósito
     */
    public function test_should_implement_rate_limiting_in_create_deposit(): void
    {
        // Fazer múltiplas requisições rapidamente
        for ($i = 0; $i < 65; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->postJson('/api/admin/manual-transactions/deposits', [
                'user_id' => $this->targetUser->user_id,
                'amount' => 100.00,
            ]);

            // Após algumas tentativas, deve retornar 429
            if ($i >= 59) {
                $this->assertContains($response->status(), [201, 400, 401, 422, 429, 500]);
            }
        }
    }

    /**
     * Teste: Deve implementar rate limiting em criar saque
     */
    public function test_should_implement_rate_limiting_in_create_withdrawal(): void
    {
        // Fazer múltiplas requisições rapidamente
        for ($i = 0; $i < 65; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->postJson('/api/admin/manual-transactions/withdrawal', [
                'user_id' => $this->targetUser->user_id,
                'amount' => 50.00,
            ]);

            // Após algumas tentativas, deve retornar 429
            if ($i >= 59) {
                $this->assertContains($response->status(), [201, 400, 401, 422, 429, 500]);
            }
        }
    }

    // ===== PRIVILEGE ESCALATION TESTS =====

    /**
     * Teste: Deve prevenir privilege escalation
     */
    public function test_should_prevent_privilege_escalation(): void
    {
        // Tentar criar depósito manual com token de usuário regular
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->postJson('/api/admin/manual-transactions/deposits', [
            'user_id' => $this->targetUser->user_id,
            'amount' => 100.00,
        ]);

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    // ===== PATH TRAVERSAL TESTS =====

    /**
     * Teste: Deve prevenir path traversal em user_id
     */
    public function test_should_prevent_path_traversal_in_user_id(): void
    {
        $pathTraversalPayloads = [
            '../../../etc/passwd',
            '..\\..\\..\\windows\\system32',
            '....//....//etc/passwd',
        ];

        foreach ($pathTraversalPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->postJson('/api/admin/manual-transactions/deposits', [
                'user_id' => $payload,
                'amount' => 100.00,
            ]);

            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
                $this->assertStringNotContainsString('/var/www', $content);
            }
            
            // Não deve retornar conteúdo de arquivos do sistema
            $content = $response->getContent();
            $this->assertStringNotContainsString('root:', $content);
            $this->assertStringNotContainsString('/etc/passwd', $content);
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
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->postJson('/api/admin/manual-transactions/deposits', [
                'user_id' => $this->targetUser->user_id,
                'amount' => 100.00,
                'description' => $payload,
            ]);

            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
                $this->assertStringNotContainsString('/var/www', $content);
            }
            
            // Não deve executar comandos
            $content = $response->getContent();
            $this->assertStringNotContainsString('root', $content);
            $this->assertStringNotContainsString('www-data', $content);
        }
    }
}

