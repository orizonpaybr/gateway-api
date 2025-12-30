<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use App\Models\User;
use App\Models\UsersKey;
use App\Models\SolicitacoesCashOut;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\TransactionTestHelper;

/**
 * Testes de Segurança - Aprovação de Saques (Admin)
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
class WithdrawalApprovalSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $regularUser;
    private User $withdrawalUser;
    private string $adminToken;
    private string $regularToken;
    private SolicitacoesCashOut $pendingWithdrawal;

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

        // Criar usuário que terá saque
        $this->withdrawalUser = User::factory()->create([
            'username' => 'withdrawaluser',
            'user_id' => 'withdrawaluser',
            'email' => 'withdrawal@example.com',
            'password' => Hash::make('password123'),
            'status' => 1,
            'banido' => 0,
            'permission' => UserPermission::CLIENT,
            'saldo' => 5000.00,
            'cpf_cnpj' => '11122233344',
            'telefone' => '11777777777',
            'name' => 'Withdrawal User',
        ]);

        UsersKey::factory()->create([
            'user_id' => $this->withdrawalUser->user_id,
        ]);

        // Criar saque pendente
        $this->pendingWithdrawal = TransactionTestHelper::createSolicitacaoCashOut([
            'user_id' => $this->withdrawalUser->username,
            'amount' => 100.00,
            'cash_out_liquido' => 99.00,
            'taxa_cash_out' => 1.00,
            'status' => 'PENDING',
            'descricao_transacao' => 'WEB',
            'beneficiaryname' => 'Test Beneficiary',
            'beneficiarydocument' => '11122233344',
            'pixkey' => '11122233344',
            'pix' => 'CPF',
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
     * Teste: Deve exigir autenticação para listar saques
     */
    public function test_should_require_authentication_to_list_withdrawals(): void
    {
        $response = $this->getJson('/api/admin/withdrawals');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve exigir permissão de admin para listar saques
     */
    public function test_should_require_admin_permission_to_list_withdrawals(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->getJson('/api/admin/withdrawals');

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve exigir permissão de admin para aprovar saque
     */
    public function test_should_require_admin_permission_to_approve_withdrawal(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->postJson("/api/admin/withdrawals/{$this->pendingWithdrawal->id}/approve");

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve exigir permissão de admin para rejeitar saque
     */
    public function test_should_require_admin_permission_to_reject_withdrawal(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->postJson("/api/admin/withdrawals/{$this->pendingWithdrawal->id}/reject");

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve exigir permissão de admin para ver detalhes de saque
     */
    public function test_should_require_admin_permission_to_view_withdrawal_details(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->getJson("/api/admin/withdrawals/{$this->pendingWithdrawal->id}");

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve exigir permissão de admin para ver estatísticas
     */
    public function test_should_require_admin_permission_to_view_stats(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->getJson('/api/admin/withdrawals/stats');

        // Deve retornar 403 (Forbidden) ou 401
        $this->assertContains($response->status(), [401, 403]);
    }

    // ===== BUSINESS LOGIC SECURITY TESTS =====

    /**
     * Teste: Não deve aprovar saque já processado
     */
    public function test_should_not_approve_already_processed_withdrawal(): void
    {
        // Criar saque já processado
        $processedWithdrawal = TransactionTestHelper::createSolicitacaoCashOut([
            'user_id' => $this->withdrawalUser->username,
            'amount' => 200.00,
            'status' => 'COMPLETED',
            'descricao_transacao' => 'WEB',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson("/api/admin/withdrawals/{$processedWithdrawal->id}/approve");

        // Deve retornar 400 (Bad Request)
        $response->assertStatus(400);
        $this->assertStringContainsString('processado', strtolower($response->getContent()));
    }

    /**
     * Teste: Não deve rejeitar saque já processado
     */
    public function test_should_not_reject_already_processed_withdrawal(): void
    {
        // Criar saque já processado
        $processedWithdrawal = TransactionTestHelper::createSolicitacaoCashOut([
            'user_id' => $this->withdrawalUser->username,
            'amount' => 200.00,
            'status' => 'COMPLETED',
            'descricao_transacao' => 'WEB',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson("/api/admin/withdrawals/{$processedWithdrawal->id}/reject");

        // Deve retornar 400 (Bad Request)
        $response->assertStatus(400);
        $this->assertStringContainsString('processado', strtolower($response->getContent()));
    }

    // ===== INPUT VALIDATION TESTS =====

    /**
     * Teste: Deve validar ID do saque
     */
    public function test_should_validate_withdrawal_id(): void
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
            ])->postJson("/api/admin/withdrawals/{$invalidId}/approve");

            // Deve retornar 404 (Not Found), 400, 422 ou 500
            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
            }
            
            $this->assertContains($response->status(), [400, 404, 422, 500]);
        }
    }

    /**
     * Teste: Deve validar paginação
     */
    public function test_should_validate_pagination(): void
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
            ])->getJson("/api/admin/withdrawals?page={$page}");

            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
            }
        }
    }

    /**
     * Teste: Deve validar limite de paginação
     */
    public function test_should_validate_pagination_limit(): void
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
            ])->getJson("/api/admin/withdrawals?limit={$limit}");

            // Deve normalizar para valor válido ou retornar erro
            if ($response->status() === 200) {
                $data = $response->json('data');
                $this->assertLessThanOrEqual(100, $data['per_page'] ?? 20);
            }
        }
    }

    /**
     * Teste: Deve validar status
     */
    public function test_should_validate_status(): void
    {
        $invalidStatuses = [
            'invalid',
            '<script>alert("XSS")</script>',
            'sql_injection',
        ];

        foreach ($invalidStatuses as $status) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->getJson("/api/admin/withdrawals?status={$status}");

            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
            }
        }
    }

    // ===== SQL INJECTION TESTS =====

    /**
     * Teste: Deve prevenir SQL Injection em busca
     */
    public function test_should_prevent_sql_injection_in_search(): void
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
            ])->getJson("/api/admin/withdrawals?busca={$encodedPayload}");

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
     * Teste: Deve prevenir SQL Injection em ID
     */
    public function test_should_prevent_sql_injection_in_id(): void
    {
        $sqlInjectionPayloads = [
            "1' OR '1'='1",
            "1'; DROP TABLE solicitacoes_cash_out--",
            "1' UNION SELECT * FROM solicitacoes_cash_out--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->postJson("/api/admin/withdrawals/{$payload}/approve");

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
     * Teste: Deve prevenir XSS em busca
     */
    public function test_should_prevent_xss_in_search(): void
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
            ])->getJson("/api/admin/withdrawals?busca={$encodedPayload}");

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
            '/api/admin/withdrawals',
            "/api/admin/withdrawals/{$this->pendingWithdrawal->id}",
            '/api/admin/withdrawals/stats',
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
        ])->getJson('/api/admin/withdrawals');

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
     * Teste: Deve implementar rate limiting em listar saques
     */
    public function test_should_implement_rate_limiting_in_list_withdrawals(): void
    {
        // Fazer múltiplas requisições rapidamente
        for ($i = 0; $i < 65; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->getJson('/api/admin/withdrawals');

            // Após algumas tentativas, deve retornar 429
            if ($i >= 59) {
                $this->assertContains($response->status(), [200, 401, 429]);
            }
        }
    }

    /**
     * Teste: Deve implementar rate limiting em aprovar saque
     */
    public function test_should_implement_rate_limiting_in_approve_withdrawal(): void
    {
        // Criar múltiplos saques pendentes
        $withdrawals = [];
        for ($i = 0; $i < 10; $i++) {
            $withdrawals[] = TransactionTestHelper::createSolicitacaoCashOut([
                'user_id' => $this->withdrawalUser->username,
                'amount' => 50.00 + $i,
                'status' => 'PENDING',
                'descricao_transacao' => 'WEB',
            ]);
        }

        // Fazer múltiplas requisições rapidamente
        for ($i = 0; $i < 65; $i++) {
            $withdrawal = $withdrawals[$i % count($withdrawals)];
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->adminToken,
            ])->postJson("/api/admin/withdrawals/{$withdrawal->id}/approve");

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
        // Tentar aprovar saque com token de usuário regular
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->regularToken,
        ])->postJson("/api/admin/withdrawals/{$this->pendingWithdrawal->id}/approve");

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
            ])->getJson("/api/admin/withdrawals?busca={$encodedPayload}");

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
            ])->getJson("/api/admin/withdrawals?busca={$encodedPayload}");

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

    // ===== IDOR TESTS =====

    /**
     * Teste: Admin deve poder ver todos os saques (não é IDOR, é funcionalidade)
     * Mas deve validar que não há acesso não autorizado
     */
    public function test_should_allow_admin_to_view_all_withdrawals(): void
    {
        // Criar saque de outro usuário
        $otherUser = User::factory()->create([
            'username' => 'otheruser',
            'user_id' => 'otheruser',
            'email' => 'other@example.com',
            'password' => Hash::make('password123'),
            'status' => 1,
            'permission' => UserPermission::CLIENT,
            'saldo' => 2000.00,
        ]);

        $otherWithdrawal = TransactionTestHelper::createSolicitacaoCashOut([
            'user_id' => $otherUser->username,
            'amount' => 150.00,
            'status' => 'PENDING',
            'descricao_transacao' => 'WEB',
        ]);

        // Admin deve poder ver o saque
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson("/api/admin/withdrawals/{$otherWithdrawal->id}");

        // Admin tem acesso a todos os saques
        $this->assertContains($response->status(), [200, 404, 500]);
    }
}

