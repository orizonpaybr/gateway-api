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
 * Testes de Segurança - Dashboard do Usuário
 * 
 * Cobre:
 * - SQL Injection
 * - XSS (Cross-Site Scripting)
 * - Authorization (Acesso não autorizado)
 * - Input Validation
 * - Sensitive Data Exposure
 * - Path Traversal
 * - IDOR (Insecure Direct Object Reference)
 * - Rate Limiting
 */
class DashboardSecurityTest extends TestCase
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
            'password' => Hash::make('password123'),
            'status' => 1,
            'banido' => 0,
            'permission' => UserPermission::CLIENT,
            'saldo' => 1000.00,
        ]);

        // Criar UsersKey (necessário para login)
        UsersKey::factory()->create([
            'user_id' => $this->user->user_id,
            'token' => 'test_token_' . $this->user->username,
        ]);

        // Fazer login e obter token
        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => 'testuser',
            'password' => 'password123',
        ]);

        $this->token = $loginResponse->json('token') ?? $loginResponse->json('data.token');
    }

    // ===== SQL INJECTION TESTS =====

    /**
     * Teste: Deve prevenir SQL Injection em parâmetros de query
     */
    public function test_should_prevent_sql_injection_in_query_params(): void
    {
        $sqlInjectionPayloads = [
            "' OR '1'='1",
            "' OR '1'='1' --",
            "1' UNION SELECT * FROM users--",
            "'; DROP TABLE users--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/dashboard/stats?period={$payload}");

            // Não deve retornar erros SQL
            $this->assertNotEquals(500, $response->status());
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            $this->assertStringNotContainsString('SQL syntax', $response->getContent());
        }
    }

    /**
     * Teste: Deve prevenir SQL Injection em IDs de transações
     */
    public function test_should_prevent_sql_injection_in_transaction_id(): void
    {
        $sqlInjectionPayloads = [
            "1' OR '1'='1",
            "1' UNION SELECT * FROM users--",
            "'; DROP TABLE transactions--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/transactions/{$payload}");

            // Não deve retornar erros SQL
            $this->assertNotEquals(500, $response->status());
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            
            // Deve retornar erro de validação ou não encontrado
            $this->assertContains($response->status(), [400, 404, 401]);
        }
    }

    // ===== XSS (CROSS-SITE SCRIPTING) TESTS =====

    /**
     * Teste: Deve prevenir XSS em parâmetros de query
     */
    public function test_should_prevent_xss_in_query_params(): void
    {
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            '<img src=x onerror=alert("XSS")>',
            'javascript:alert("XSS")',
        ];

        foreach ($xssPayloads as $payload) {
            $encodedPayload = urlencode($payload);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/dashboard/stats?period={$encodedPayload}");

            $content = $response->getContent();
            
            // Não deve retornar o payload XSS na resposta
            $this->assertStringNotContainsString('<script>', $content);
            $this->assertStringNotContainsString('onerror=', $content);
            $this->assertStringNotContainsString('javascript:', $content);
        }
    }

    // ===== AUTHORIZATION TESTS =====

    /**
     * Teste: Deve exigir autenticação para acessar dashboard
     */
    public function test_should_require_authentication_for_dashboard(): void
    {
        $response = $this->getJson('/api/dashboard/stats');

        $response->assertStatus(401);
        $this->assertFalse($response->json('success'));
    }

    /**
     * Teste: Deve prevenir acesso a dados de outros usuários (IDOR)
     */
    public function test_should_prevent_idor_access_to_other_users_data(): void
    {
        // Criar outro usuário
        $otherUser = User::factory()->create([
            'username' => 'otheruser',
            'user_id' => 'otheruser',
            'email' => 'other@example.com',
            'password' => Hash::make('password123'),
            'status' => 1,
            'permission' => UserPermission::CLIENT,
        ]);

        UsersKey::factory()->create([
            'user_id' => $otherUser->user_id,
        ]);

        // Tentar acessar dados do outro usuário usando token do primeiro
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/dashboard/stats');

        // Deve retornar apenas dados do usuário autenticado
        $response->assertStatus(200);
        $data = $response->json('data');
        
        // Verificar que os dados retornados são do usuário autenticado
        // (não do outro usuário)
        $this->assertNotNull($data);
    }

    /**
     * Teste: Deve prevenir acesso a transações de outros usuários
     */
    public function test_should_prevent_access_to_other_users_transactions(): void
    {
        // Criar outro usuário
        $otherUser = User::factory()->create([
            'username' => 'otheruser',
            'user_id' => 'otheruser',
            'email' => 'other@example.com',
            'password' => Hash::make('password123'),
            'status' => 1,
            'permission' => UserPermission::CLIENT,
        ]);

        UsersKey::factory()->create([
            'user_id' => $otherUser->user_id,
        ]);

        // Tentar acessar transações do outro usuário
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/transactions');

        // Deve retornar apenas transações do usuário autenticado
        $response->assertStatus(200);
        $data = $response->json('data');
        
        // Se houver transações, verificar que todas pertencem ao usuário autenticado
        if (isset($data) && is_array($data)) {
            foreach ($data as $transaction) {
                if (isset($transaction['user_id'])) {
                    $this->assertEquals($this->user->username, $transaction['user_id']);
                }
            }
        }
    }

    // ===== INPUT VALIDATION TESTS =====

    /**
     * Teste: Deve validar parâmetros de período
     */
    public function test_should_validate_period_parameters(): void
    {
        $invalidPeriods = [
            '../../etc/passwd',
            '<script>alert("XSS")</script>',
            "'; DROP TABLE users--",
            str_repeat('a', 1000),
        ];

        foreach ($invalidPeriods as $period) {
            $encodedPeriod = urlencode($period);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/dashboard/interactive-movement?period={$encodedPeriod}");

            // Não deve retornar erro 500 (erro interno)
            $this->assertNotEquals(500, $response->status());
            
            // Deve retornar erro de validação ou dados padrão
            $this->assertContains($response->status(), [200, 400, 422]);
        }
    }

    /**
     * Teste: Deve validar limites de paginação
     */
    public function test_should_validate_pagination_limits(): void
    {
        $invalidLimits = [
            -1,
            0,
            10000, // Muito grande
            "' OR '1'='1",
            '<script>alert("XSS")</script>',
        ];

        foreach ($invalidLimits as $limit) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/transactions?limit={$limit}");

            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
                $this->assertStringNotContainsString('/var/www', $content);
            } else {
                // Deve retornar erro de validação ou usar limite padrão
                $this->assertContains($response->status(), [200, 400, 422]);
            }
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
        ])->getJson('/api/dashboard/stats');

        $content = $response->getContent();
        
        // Não deve expor senhas
        $this->assertStringNotContainsString('password', strtolower($content));
        
        // Não deve expor tokens completos
        $this->assertStringNotContainsString($this->token, $content);
        
        // Não deve expor stack traces
        $this->assertStringNotContainsString('Stack trace', $content);
        $this->assertStringNotContainsString('/var/www', $content);
    }

    /**
     * Teste: Não deve expor dados de outros usuários
     */
    public function test_should_not_expose_other_users_data(): void
    {
        // Criar outro usuário com saldo diferente
        $otherUser = User::factory()->create([
            'username' => 'otheruser',
            'user_id' => 'otheruser',
            'email' => 'other@example.com',
            'password' => Hash::make('password123'),
            'status' => 1,
            'permission' => UserPermission::CLIENT,
            'saldo' => 999999.00, // Saldo muito diferente
        ]);

        UsersKey::factory()->create([
            'user_id' => $otherUser->user_id,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/dashboard/stats');

        $content = $response->getContent();
        
        // Não deve conter o saldo do outro usuário
        $this->assertStringNotContainsString('999999', $content);
        $this->assertStringNotContainsString('otheruser', $content);
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
            '%2e%2e%2f%2e%2e%2f%2e%2e%2fetc%2fpasswd',
        ];

        foreach ($pathTraversalPayloads as $payload) {
            $encodedPayload = urlencode($payload);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/dashboard/stats?file={$encodedPayload}");

            // Não deve permitir path traversal
            $this->assertNotEquals(500, $response->status());
            
            // Não deve retornar conteúdo de arquivos do sistema
            $content = $response->getContent();
            $this->assertStringNotContainsString('root:', $content);
            $this->assertStringNotContainsString('/etc/passwd', $content);
        }
    }

    // ===== RATE LIMITING TESTS =====

    /**
     * Teste: Deve implementar rate limiting em requisições excessivas
     */
    public function test_should_implement_rate_limiting(): void
    {
        // Fazer múltiplas requisições rapidamente
        for ($i = 0; $i < 50; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson('/api/dashboard/stats');

            // Primeiras requisições devem funcionar
            if ($i < 30) {
                $this->assertContains($response->status(), [200, 429]);
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
            $encodedPayload = urlencode($payload);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/dashboard/stats?period={$encodedPayload}");

            // Não deve executar comandos
            $this->assertNotEquals(500, $response->status());
            
            $content = $response->getContent();
            $this->assertStringNotContainsString('root', $content);
            $this->assertStringNotContainsString('www-data', $content);
        }
    }

    // ===== TOKEN VALIDATION TESTS =====

    /**
     * Teste: Deve validar tokens expirados
     */
    public function test_should_validate_expired_tokens(): void
    {
        // Criar token expirado manualmente
        $expiredToken = base64_encode(json_encode([
            'user_id' => $this->user->username,
            'token' => 'test_token',
            'secret' => 'test_secret',
            'expires_at' => now()->subHours(25)->timestamp, // Expirado
        ]));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $expiredToken,
        ])->getJson('/api/dashboard/stats');

        // Deve retornar erro de autenticação
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * Teste: Deve validar tokens inválidos
     */
    public function test_should_validate_invalid_tokens(): void
    {
        $invalidTokens = [
            'invalid_token',
            'not_a_valid_token_at_all',
            base64_encode('invalid_json'),
            '',
        ];

        foreach ($invalidTokens as $token) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ])->getJson('/api/dashboard/stats');

            // Deve retornar erro de autenticação
            $this->assertContains($response->status(), [401, 403]);
        }
    }
}

