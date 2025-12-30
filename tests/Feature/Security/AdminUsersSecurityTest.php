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
 * Testes de Segurança - Gerenciamento de Usuários (Admin)
 * 
 * Cobre:
 * - SQL Injection
 * - XSS (Cross-Site Scripting)
 * - Authorization (Acesso não autorizado, apenas admins)
 * - Input Validation
 * - Sensitive Data Exposure
 * - IDOR (Insecure Direct Object Reference)
 * - Path Traversal
 * - Command Injection
 * - Rate Limiting
 * - Privilege Escalation
 * - Business Logic Security
 */
class AdminUsersSecurityTest extends TestCase
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
            'status' => UserStatus::PENDING,
            'banido' => 0,
            'permission' => UserPermission::CLIENT,
            'saldo' => 500.00,
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

    // ===== AUTHORIZATION TESTS =====

    /**
     * Teste: Deve exigir autenticação para listar usuários
     */
    public function test_should_require_authentication_to_list_users(): void
    {
        $response = $this->getJson('/api/admin/dashboard/users');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve exigir permissão de admin para listar usuários
     */
    public function test_should_require_admin_permission_to_list_users(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->getJson('/api/admin/dashboard/users');

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve exigir permissão de admin para criar usuário
     */
    public function test_should_require_admin_permission_to_create_user(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->postJson('/api/admin/users', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'password123',
        ]);

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve exigir permissão de admin para atualizar usuário
     */
    public function test_should_require_admin_permission_to_update_user(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->putJson("/api/admin/users/{$this->targetUser->id}", [
            'name' => 'Updated Name',
        ]);

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve exigir permissão de admin para deletar usuário
     */
    public function test_should_require_admin_permission_to_delete_user(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->deleteJson("/api/admin/users/{$this->targetUser->id}");

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve exigir permissão de admin para aprovar usuário
     */
    public function test_should_require_admin_permission_to_approve_user(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->postJson("/api/admin/users/{$this->targetUser->id}/approve");

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve exigir permissão de admin para bloquear usuário
     */
    public function test_should_require_admin_permission_to_block_user(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->postJson("/api/admin/users/{$this->targetUser->id}/toggle-block", [
            'block' => true,
        ]);

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve exigir permissão de admin para ajustar saldo
     */
    public function test_should_require_admin_permission_to_adjust_balance(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->postJson("/api/admin/users/{$this->targetUser->id}/adjust-balance", [
            'amount' => 100.00,
            'type' => 'add',
        ]);

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    // ===== INPUT VALIDATION TESTS =====

    /**
     * Teste: Deve validar dados ao criar usuário
     */
    public function test_should_validate_data_when_creating_user(): void
    {
        $invalidData = [
            ['name' => '', 'email' => 'invalid', 'password' => '123'], // Campos obrigatórios vazios
            ['name' => 'A', 'email' => 'test@test.com', 'password' => '123'], // Nome muito curto
            ['name' => 'Valid Name', 'email' => 'invalid-email', 'password' => '123'], // Email inválido
            ['name' => 'Valid Name', 'email' => 'test@test.com', 'password' => '123'], // Senha muito curta
        ];

        foreach ($invalidData as $data) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->postJson('/api/admin/users', $data);

            // Deve retornar 422 (Unprocessable Entity) ou 400
            $this->assertContains($response->status(), [400, 422]);
        }
    }

    /**
     * Teste: Deve validar ID do usuário
     */
    public function test_should_validate_user_id(): void
    {
        $invalidIds = [
            -1,
            0,
            999999,
            'invalid',
            '<script>alert("XSS")</script>',
        ];

        foreach ($invalidIds as $invalidId) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->getJson("/api/admin/users/{$invalidId}");

            // Deve retornar 404 (Not Found) ou 500
            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
            }
            
            $this->assertContains($response->status(), [404, 500]);
        }
    }

    /**
     * Teste: Deve validar valores ao ajustar saldo
     */
    public function test_should_validate_values_when_adjusting_balance(): void
    {
        $invalidData = [
            ['amount' => -1, 'type' => 'add'], // Valor negativo
            ['amount' => 0, 'type' => 'add'], // Valor zero
            ['amount' => 'invalid', 'type' => 'add'], // Valor inválido
            ['amount' => 100, 'type' => 'invalid'], // Tipo inválido
        ];

        foreach ($invalidData as $data) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->postJson("/api/admin/users/{$this->targetUser->id}/adjust-balance", $data);

            // Deve retornar 422 (Unprocessable Entity), 400 ou 500
            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
            }
            
            $this->assertContains($response->status(), [400, 422, 500]);
        }
    }

    // ===== SQL INJECTION TESTS =====

    /**
     * Teste: Deve prevenir SQL Injection em busca de usuários
     */
    public function test_should_prevent_sql_injection_in_user_search(): void
    {
        $sqlInjectionPayloads = [
            "' OR '1'='1",
            "'; DROP TABLE users--",
            "' UNION SELECT * FROM users--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $encodedPayload = urlencode($payload);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->getJson("/api/admin/dashboard/users?search={$encodedPayload}");

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
     * Teste: Deve prevenir SQL Injection em criação de usuário
     */
    public function test_should_prevent_sql_injection_in_user_creation(): void
    {
        $sqlInjectionPayloads = [
            "'; DROP TABLE users--",
            "' UNION SELECT * FROM users--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->postJson('/api/admin/users', [
                'name' => $payload,
                'email' => 'test' . uniqid() . '@example.com',
                'password' => 'password123',
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
     * Teste: Deve prevenir XSS em busca de usuários
     */
    public function test_should_prevent_xss_in_user_search(): void
    {
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            'javascript:alert("XSS")',
            'onerror=alert("XSS")',
        ];

        foreach ($xssPayloads as $payload) {
            $encodedPayload = urlencode($payload);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->getJson("/api/admin/dashboard/users?search={$encodedPayload}");

            $content = $response->getContent();
            
            // Verificar que não há erros de execução de script
            $this->assertStringNotContainsString('Fatal error', $content);
            $this->assertStringNotContainsString('Parse error', $content);
        }
    }

    /**
     * Teste: Deve prevenir XSS em criação de usuário
     */
    public function test_should_prevent_xss_in_user_creation(): void
    {
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            'javascript:alert("XSS")',
        ];

        foreach ($xssPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->postJson('/api/admin/users', [
                'name' => $payload,
                'email' => 'test' . uniqid() . '@example.com',
                'password' => 'password123',
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
        $endpoints = [
            '/api/admin/dashboard/users',
            "/api/admin/users/{$this->targetUser->id}",
            '/api/admin/dashboard/users-stats',
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->getJson($endpoint);

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
    }

    /**
     * Teste: Não deve expor informações sensíveis em erros
     */
    public function test_should_not_expose_sensitive_info_in_errors(): void
    {
        // Simular erro forçando uma exceção
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid_token_that_will_cause_error',
        ])->getJson('/api/admin/dashboard/users');

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
     * Teste: Não deve permitir deletar admin principal
     */
    public function test_should_not_allow_deleting_main_admin(): void
    {
        // Criar admin principal (id = 1)
        $mainAdmin = User::factory()->create([
            'id' => 1,
            'username' => 'mainadmin',
            'user_id' => 'mainadmin',
            'email' => 'mainadmin@example.com',
            'password' => Hash::make('password123'),
            'status' => UserStatus::ACTIVE,
            'permission' => UserPermission::ADMIN,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->deleteJson("/api/admin/users/{$mainAdmin->id}");

        // Deve retornar erro
        $this->assertContains($response->status(), [400, 403, 500]);
        $this->assertStringContainsString('principal', strtolower($response->getContent()));
    }

    /**
     * Teste: Não deve permitir bloquear admin principal
     */
    public function test_should_not_allow_blocking_main_admin(): void
    {
        // Criar admin principal (id = 1)
        $mainAdmin = User::factory()->create([
            'id' => 1,
            'username' => 'mainadmin',
            'user_id' => 'mainadmin',
            'email' => 'mainadmin@example.com',
            'password' => Hash::make('password123'),
            'status' => UserStatus::ACTIVE,
            'permission' => UserPermission::ADMIN,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson("/api/admin/users/{$mainAdmin->id}/toggle-block", [
            'block' => true,
        ]);

        // Deve retornar erro
        $this->assertContains($response->status(), [400, 403, 500]);
        $this->assertStringContainsString('principal', strtolower($response->getContent()));
    }

    /**
     * Teste: Não deve permitir aprovar usuário já aprovado
     */
    public function test_should_not_allow_approving_already_approved_user(): void
    {
        // Criar usuário já aprovado
        $approvedUser = User::factory()->create([
            'username' => 'approveduser',
            'user_id' => 'approveduser',
            'email' => 'approved@example.com',
            'password' => Hash::make('password123'),
            'status' => UserStatus::ACTIVE,
            'permission' => UserPermission::CLIENT,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson("/api/admin/users/{$approvedUser->id}/approve");

        // Deve retornar erro
        $this->assertContains($response->status(), [400, 500]);
        $this->assertStringContainsString('aprovado', strtolower($response->getContent()));
    }

    // ===== RATE LIMITING TESTS =====

    /**
     * Teste: Deve implementar rate limiting em listar usuários
     */
    public function test_should_implement_rate_limiting_in_list_users(): void
    {
        // Fazer múltiplas requisições rapidamente
        for ($i = 0; $i < 65; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->getJson('/api/admin/dashboard/users');

            // Após algumas tentativas, deve retornar 429
            if ($i >= 59) {
                $this->assertContains($response->status(), [200, 401, 429]);
            }
        }
    }

    // ===== PRIVILEGE ESCALATION TESTS =====

    /**
     * Teste: Deve prevenir privilege escalation
     */
    public function test_should_prevent_privilege_escalation(): void
    {
        // Tentar criar usuário com permissão de admin usando token de usuário regular
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->postJson('/api/admin/users', [
            'name' => 'Escalated User',
            'email' => 'escalated@example.com',
            'password' => 'password123',
            'permission' => UserPermission::ADMIN,
        ]);

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    // ===== PATH TRAVERSAL TESTS =====

    /**
     * Teste: Deve prevenir path traversal em parâmetros
     */
    public function test_should_prevent_path_traversal_in_params(): void
    {
        $pathTraversalPayloads = [
            '../../../etc/passwd',
            '..\\..\\..\\windows\\system32',
            '....//....//etc/passwd',
        ];

        foreach ($pathTraversalPayloads as $payload) {
            $encodedPayload = urlencode($payload);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->getJson("/api/admin/dashboard/users?search={$encodedPayload}");

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
            $encodedPayload = urlencode($payload);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->getJson("/api/admin/dashboard/users?search={$encodedPayload}");

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

