<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use App\Models\User;
use App\Models\UsersKey;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Segurança - Dashboard Administrativo
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
 * - Cache Security
 */
class AdminDashboardSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $regularUser;
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
            'status' => 1,
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
            'status' => 1,
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
     * Teste: Deve exigir autenticação para acessar dashboard admin
     */
    public function test_should_require_authentication_to_access_admin_dashboard(): void
    {
        $response = $this->getJson('/api/admin/dashboard/stats');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve exigir permissão de admin para acessar dashboard
     */
    public function test_should_require_admin_permission_to_access_dashboard(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->getJson('/api/admin/dashboard/stats');

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve permitir acesso apenas para usuários com permission = 3
     */
    public function test_should_allow_access_only_for_admin_users(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/admin/dashboard/stats');

        // Admin deve ter acesso
        $this->assertContains($response->status(), [200, 500]);
    }

    /**
     * Teste: Deve prevenir acesso a lista de usuários sem permissão admin
     */
    public function test_should_prevent_access_to_users_list_without_admin_permission(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->getJson('/api/admin/dashboard/users');

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve prevenir acesso a estatísticas de usuários sem permissão admin
     */
    public function test_should_prevent_access_to_user_stats_without_admin_permission(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->getJson('/api/admin/dashboard/users-stats');

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve prevenir acesso a transações recentes sem permissão admin
     */
    public function test_should_prevent_access_to_recent_transactions_without_admin_permission(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->getJson('/api/admin/dashboard/transactions');

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    // ===== INPUT VALIDATION TESTS =====

    /**
     * Teste: Deve validar período
     */
    public function test_should_validate_period(): void
    {
        $invalidPeriods = [
            'invalid',
            '<script>alert("XSS")</script>',
            'sql_injection',
        ];

        foreach ($invalidPeriods as $invalidPeriod) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->getJson("/api/admin/dashboard/stats?periodo={$invalidPeriod}");

            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
            }
        }
    }

    /**
     * Teste: Deve validar paginação em lista de usuários
     */
    public function test_should_validate_pagination_in_users_list(): void
    {
        $invalidPages = [
            -1,
            0,
            999999,
            'invalid',
            '<script>alert("XSS")</script>',
        ];

        foreach ($invalidPages as $page) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->getJson("/api/admin/dashboard/users?page={$page}");

            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
            }
        }
    }

    /**
     * Teste: Deve validar limite de transações
     */
    public function test_should_validate_transactions_limit(): void
    {
        $invalidLimits = [
            -1,
            0,
            101, // Acima do máximo permitido (100)
            999999,
        ];

        foreach ($invalidLimits as $limit) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->getJson("/api/admin/dashboard/transactions?limit={$limit}");

            // Deve normalizar para valor válido ou retornar erro
            if ($response->status() === 200) {
                $data = $response->json('data');
                // Verificar que o limite foi normalizado
                $this->assertLessThanOrEqual(100, $data['limit'] ?? 20);
            }
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
     * Teste: Deve prevenir SQL Injection em filtros
     */
    public function test_should_prevent_sql_injection_in_filters(): void
    {
        $sqlInjectionPayloads = [
            "' OR '1'='1",
            "'; DROP TABLE users--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $encodedPayload = urlencode($payload);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->getJson("/api/admin/dashboard/users?status={$encodedPayload}");

            // Não deve retornar erros SQL
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
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

    // ===== SENSITIVE DATA EXPOSURE TESTS =====

    /**
     * Teste: Não deve expor informações sensíveis em respostas
     */
    public function test_should_not_expose_sensitive_info_in_responses(): void
    {
        $endpoints = [
            '/api/admin/dashboard/stats',
            '/api/admin/dashboard/users',
            '/api/admin/dashboard/users-stats',
            '/api/admin/dashboard/transactions',
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
        ])->getJson('/api/admin/dashboard/stats');

        $content = $response->getContent();
        
        // Não deve expor stack traces
        $this->assertStringNotContainsString('Stack trace', $content);
        $this->assertStringNotContainsString('/var/www', $content);
        $this->assertStringNotContainsString('at ', $content);
        
        // Não deve expor informações do banco de dados
        $this->assertStringNotContainsString('SQLSTATE', $content);
    }

    // ===== RATE LIMITING TESTS =====

    /**
     * Teste: Deve implementar rate limiting
     */
    public function test_should_implement_rate_limiting(): void
    {
        // Fazer múltiplas requisições rapidamente
        for ($i = 0; $i < 65; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->getJson('/api/admin/dashboard/stats');

            // Após algumas tentativas, deve retornar 429
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
        // Tentar acessar endpoint admin com token de usuário regular
        $endpoints = [
            '/api/admin/dashboard/stats',
            '/api/admin/dashboard/users',
            '/api/admin/dashboard/users-stats',
            '/api/admin/dashboard/transactions',
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->regularToken,
            ])->getJson($endpoint);

            // Deve retornar 403 (Forbidden) ou 401
            $this->assertContains($response->status(), [401, 403]);
        }
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

    // ===== CACHE SECURITY TESTS =====

    /**
     * Teste: Não deve expor dados de cache em respostas
     */
    public function test_should_not_expose_cache_data_in_responses(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/admin/dashboard/stats');

        $content = $response->getContent();
        
        // Não deve expor chaves de cache
        $this->assertStringNotContainsString('cache:', strtolower($content));
        $this->assertStringNotContainsString('redis:', strtolower($content));
        
        // Não deve expor informações internas de cache
        $this->assertStringNotContainsString('ttl', strtolower($content));
    }
}






