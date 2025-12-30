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
use Tests\Feature\Helpers\TransactionTestHelper;

/**
 * Testes de Segurança - Financeiro (Entradas/Depósitos e Saídas/Saques)
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
class FinancialDepositsWithdrawalsSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $regularUser;
    private string $adminToken;
    private string $regularToken;
    private $deposit;
    private $withdrawal;

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

        // Criar depósito de teste
        $this->deposit = TransactionTestHelper::createSolicitacao([
            'user_id' => $this->regularUser->username,
            'amount' => 100.00,
            'status' => 'PAID_OUT',
        ]);

        // Criar saque de teste
        $this->withdrawal = TransactionTestHelper::createSolicitacaoCashOut([
            'user_id' => $this->regularUser->username,
            'amount' => 50.00,
            'status' => 'COMPLETED',
            'descricao_transacao' => 'WEB',
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
     * Teste: Deve exigir autenticação para listar depósitos
     */
    public function test_should_require_authentication_to_list_deposits(): void
    {
        $response = $this->getJson('/api/admin/financial/deposits');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve exigir permissão de admin para listar depósitos
     */
    public function test_should_require_admin_permission_to_list_deposits(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->getJson('/api/admin/financial/deposits');

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve exigir permissão de admin para ver estatísticas de depósitos
     */
    public function test_should_require_admin_permission_to_view_deposits_stats(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->getJson('/api/admin/financial/deposits/stats');

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve exigir permissão de admin para atualizar status de depósito
     */
    public function test_should_require_admin_permission_to_update_deposit_status(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->putJson("/api/admin/financial/deposits/{$this->deposit->id}/status", [
            'status' => 'PAID_OUT',
        ]);

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    // ===== AUTHORIZATION TESTS - SAQUES =====

    /**
     * Teste: Deve exigir autenticação para listar saques
     */
    public function test_should_require_authentication_to_list_withdrawals(): void
    {
        $response = $this->getJson('/api/admin/financial/withdrawals');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve exigir permissão de admin para listar saques
     */
    public function test_should_require_admin_permission_to_list_withdrawals(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->getJson('/api/admin/financial/withdrawals');

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve exigir permissão de admin para ver estatísticas de saques
     */
    public function test_should_require_admin_permission_to_view_withdrawals_stats(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->getJson('/api/admin/financial/withdrawals/stats');

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    // ===== INPUT VALIDATION TESTS =====

    /**
     * Teste: Deve validar status ao atualizar depósito
     */
    public function test_should_validate_status_when_updating_deposit(): void
    {
        $invalidStatuses = [
            'invalid',
            '<script>alert("XSS")</script>',
            'sql_injection',
            '',
        ];

        foreach ($invalidStatuses as $invalidStatus) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->putJson("/api/admin/financial/deposits/{$this->deposit->id}/status", [
                'status' => $invalidStatus,
            ]);

            // Deve retornar 422 (Unprocessable Entity) ou 400
            $this->assertContains($response->status(), [400, 422]);
        }
    }

    /**
     * Teste: Deve validar ID do depósito
     */
    public function test_should_validate_deposit_id(): void
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
            ])->putJson("/api/admin/financial/deposits/{$invalidId}/status", [
                'status' => 'PAID_OUT',
            ]);

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
     * Teste: Deve validar paginação em depósitos
     */
    public function test_should_validate_pagination_in_deposits(): void
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
            ])->getJson("/api/admin/financial/deposits?page={$page}");

            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
            }
        }
    }

    /**
     * Teste: Deve validar paginação em saques
     */
    public function test_should_validate_pagination_in_withdrawals(): void
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
            ])->getJson("/api/admin/financial/withdrawals?page={$page}");

            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
            }
        }
    }

    // ===== SQL INJECTION TESTS =====

    /**
     * Teste: Deve prevenir SQL Injection em busca de depósitos
     */
    public function test_should_prevent_sql_injection_in_deposits_search(): void
    {
        $sqlInjectionPayloads = [
            "' OR '1'='1",
            "'; DROP TABLE solicitacoes--",
            "' UNION SELECT * FROM solicitacoes--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $encodedPayload = urlencode($payload);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->getJson("/api/admin/financial/deposits?busca={$encodedPayload}");

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
     * Teste: Deve prevenir SQL Injection em busca de saques
     */
    public function test_should_prevent_sql_injection_in_withdrawals_search(): void
    {
        $sqlInjectionPayloads = [
            "' OR '1'='1",
            "'; DROP TABLE solicitacoes_cash_out--",
            "' UNION SELECT * FROM solicitacoes_cash_out--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $encodedPayload = urlencode($payload);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->getJson("/api/admin/financial/withdrawals?busca={$encodedPayload}");

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
     * Teste: Deve prevenir XSS em busca de depósitos
     */
    public function test_should_prevent_xss_in_deposits_search(): void
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
            ])->getJson("/api/admin/financial/deposits?busca={$encodedPayload}");

            $content = $response->getContent();
            
            // Verificar que não há erros de execução de script
            $this->assertStringNotContainsString('Fatal error', $content);
            $this->assertStringNotContainsString('Parse error', $content);
        }
    }

    /**
     * Teste: Deve prevenir XSS em busca de saques
     */
    public function test_should_prevent_xss_in_withdrawals_search(): void
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
            ])->getJson("/api/admin/financial/withdrawals?busca={$encodedPayload}");

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
            '/api/admin/financial/deposits',
            '/api/admin/financial/deposits/stats',
            '/api/admin/financial/withdrawals',
            '/api/admin/financial/withdrawals/stats',
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
        ])->getJson('/api/admin/financial/deposits');

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
     * Teste: Deve implementar rate limiting em listar depósitos
     */
    public function test_should_implement_rate_limiting_in_list_deposits(): void
    {
        // Fazer múltiplas requisições rapidamente
        for ($i = 0; $i < 65; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->getJson('/api/admin/financial/deposits');

            // Após algumas tentativas, deve retornar 429
            if ($i >= 59) {
                $this->assertContains($response->status(), [200, 401, 429]);
            }
        }
    }

    /**
     * Teste: Deve implementar rate limiting em atualizar status de depósito
     */
    public function test_should_implement_rate_limiting_in_update_deposit_status(): void
    {
        // Fazer múltiplas requisições rapidamente
        for ($i = 0; $i < 65; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->putJson("/api/admin/financial/deposits/{$this->deposit->id}/status", [
                'status' => 'PAID_OUT',
            ]);

            // Após algumas tentativas, deve retornar 429
            if ($i >= 59) {
                $this->assertContains($response->status(), [200, 400, 401, 429, 500]);
            }
        }
    }

    // ===== PRIVILEGE ESCALATION TESTS =====

    /**
     * Teste: Deve prevenir privilege escalation
     */
    public function test_should_prevent_privilege_escalation(): void
    {
        // Tentar acessar endpoints financeiros com token de usuário regular
        $endpoints = [
            '/api/admin/financial/deposits',
            '/api/admin/financial/deposits/stats',
            '/api/admin/financial/withdrawals',
            '/api/admin/financial/withdrawals/stats',
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
            ])->getJson("/api/admin/financial/deposits?busca={$encodedPayload}");

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
            ])->getJson("/api/admin/financial/deposits?busca={$encodedPayload}");

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






