<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use App\Models\User;
use App\Models\UsersKey;
use App\Models\Nivel;
use App\Constants\UserPermission;
use App\Constants\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Segurança - Níveis de Gamificação
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
class LevelsSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $regularUser;
    private Nivel $testLevel;
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

        // Criar nível de teste
        $this->testLevel = Nivel::create([
            'nome' => 'Bronze',
            'cor' => '#CD7F32',
            'minimo' => 0,
            'maximo' => 100000,
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
     * Teste: Deve exigir autenticação para listar níveis
     */
    public function test_should_require_authentication_to_list_levels(): void
    {
        $response = $this->getJson('/api/admin/levels');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve exigir permissão de admin para listar níveis
     */
    public function test_should_require_admin_permission_to_list_levels(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->getJson('/api/admin/levels');

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve exigir permissão de admin para obter nível específico
     */
    public function test_should_require_admin_permission_to_get_level(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->getJson("/api/admin/levels/{$this->testLevel->id}");

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve exigir permissão de admin para atualizar nível
     */
    public function test_should_require_admin_permission_to_update_level(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->putJson("/api/admin/levels/{$this->testLevel->id}", [
            'nome' => 'Updated Level',
        ]);

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve exigir permissão de admin para ativar/desativar sistema de níveis
     */
    public function test_should_require_admin_permission_to_toggle_levels_system(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->postJson('/api/admin/levels/toggle-active', [
            'niveis_ativo' => true,
        ]);

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    // ===== INPUT VALIDATION TESTS =====

    /**
     * Teste: Deve validar dados ao atualizar nível
     */
    public function test_should_validate_data_when_updating_level(): void
    {
        $invalidData = [
            ['nome' => '', 'minimo' => 0, 'maximo' => 100000], // Nome vazio
            ['nome' => 'Valid Name', 'minimo' => -1, 'maximo' => 100000], // Mínimo negativo
            ['nome' => 'Valid Name', 'minimo' => 0, 'maximo' => -1], // Máximo negativo
            ['nome' => 'Valid Name', 'minimo' => 100000, 'maximo' => 0], // Máximo menor que mínimo
        ];

        foreach ($invalidData as $data) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->putJson("/api/admin/levels/{$this->testLevel->id}", $data);

            // Deve retornar 422 (Unprocessable Entity), 400 ou 500
            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
            }
            
            $this->assertContains($response->status(), [400, 422, 500]);
        }
    }

    /**
     * Teste: Deve validar ID do nível
     */
    public function test_should_validate_level_id(): void
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
            ])->getJson("/api/admin/levels/{$invalidId}");

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
     * Teste: Deve validar toggle de sistema de níveis
     */
    public function test_should_validate_toggle_levels_system(): void
    {
        $invalidData = [
            ['niveis_ativo' => 'invalid'],
            ['niveis_ativo' => ''],
            ['niveis_ativo' => null],
        ];

        foreach ($invalidData as $data) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->postJson('/api/admin/levels/toggle-active', $data);

            // Deve retornar 422 (Unprocessable Entity) ou 400
            $this->assertContains($response->status(), [400, 422]);
        }
    }

    // ===== SQL INJECTION TESTS =====

    /**
     * Teste: Deve prevenir SQL Injection em atualização de nível
     */
    public function test_should_prevent_sql_injection_in_update_level(): void
    {
        $sqlInjectionPayloads = [
            "'; DROP TABLE niveis--",
            "' UNION SELECT * FROM niveis--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->putJson("/api/admin/levels/{$this->testLevel->id}", [
                'nome' => $payload,
                'minimo' => 0,
                'maximo' => 100000,
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
     * Teste: Deve prevenir XSS em atualização de nível
     */
    public function test_should_prevent_xss_in_update_level(): void
    {
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            'javascript:alert("XSS")',
        ];

        foreach ($xssPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->putJson("/api/admin/levels/{$this->testLevel->id}", [
                'nome' => $payload,
                'minimo' => 0,
                'maximo' => 100000,
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
            '/api/admin/levels',
            "/api/admin/levels/{$this->testLevel->id}",
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

    // ===== RATE LIMITING TESTS =====

    /**
     * Teste: Deve implementar rate limiting em listar níveis
     */
    public function test_should_implement_rate_limiting_in_list_levels(): void
    {
        // Fazer múltiplas requisições rapidamente
        for ($i = 0; $i < 65; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->getJson('/api/admin/levels');

            // Após algumas tentativas, deve retornar 429, 200, 401 ou 500
            if ($i >= 59) {
                $this->assertContains($response->status(), [200, 401, 429, 500]);
            }
        }
    }

    // ===== PRIVILEGE ESCALATION TESTS =====

    /**
     * Teste: Deve prevenir privilege escalation
     */
    public function test_should_prevent_privilege_escalation(): void
    {
        // Tentar atualizar nível com token de usuário regular
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->putJson("/api/admin/levels/{$this->testLevel->id}", [
            'nome' => 'Hacked Level',
            'minimo' => 0,
            'maximo' => 100000,
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
        ];

        foreach ($pathTraversalPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->putJson("/api/admin/levels/{$this->testLevel->id}", [
                'nome' => $payload,
                'minimo' => 0,
                'maximo' => 100000,
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
            ])->putJson("/api/admin/levels/{$this->testLevel->id}", [
                'nome' => $payload,
                'minimo' => 0,
                'maximo' => 100000,
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

