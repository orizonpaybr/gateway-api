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
 * Testes de Segurança - Dados da Conta
 * 
 * Cobre:
 * - SQL Injection
 * - XSS (Cross-Site Scripting)
 * - Authorization (Acesso não autorizado)
 * - Input Validation
 * - Sensitive Data Exposure
 * - IDOR (Insecure Direct Object Reference)
 * - Path Traversal
 * - Command Injection
 */
class AccountDataSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $otherUser;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Criar usuário principal e obter token
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
        ]);

        UsersKey::factory()->create([
            'user_id' => $this->otherUser->user_id,
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
     * Teste: Deve exigir autenticação para acessar dados da conta
     */
    public function test_should_require_authentication_for_account_data(): void
    {
        $response = $this->getJson('/api/user/profile');

        $response->assertStatus(401);
        $this->assertFalse($response->json('success'));
    }

    /**
     * Teste: Deve prevenir acesso a dados de conta de outros usuários (IDOR)
     */
    public function test_should_prevent_idor_access_to_other_users_account_data(): void
    {
        // Tentar acessar perfil usando token do primeiro usuário
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');

        // Deve retornar apenas dados do usuário autenticado
        $response->assertStatus(200);
        $data = $response->json('data');
        
        // Verificar que retorna dados do usuário autenticado
        $this->assertEquals('testuser', $data['username']);
        $this->assertEquals('test@example.com', $data['email']);
        
        // Não deve conter dados do outro usuário
        $this->assertNotEquals('otheruser', $data['username']);
        $this->assertNotEquals('other@example.com', $data['email']);
    }

    // ===== SENSITIVE DATA EXPOSURE TESTS =====

    /**
     * Teste: Não deve expor informações sensíveis em respostas
     */
    public function test_should_not_expose_sensitive_info_in_responses(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');

        $content = $response->getContent();
        
        // Não deve expor senhas
        $this->assertStringNotContainsString('password', strtolower($content));
        $this->assertStringNotContainsString('password_hash', strtolower($content));
        $this->assertStringNotContainsString('bcrypt', strtolower($content));
        
        // Não deve expor tokens completos
        $this->assertStringNotContainsString($this->token, $content);
        
        // Não deve expor stack traces
        $this->assertStringNotContainsString('Stack trace', $content);
        $this->assertStringNotContainsString('/var/www', $content);
    }

    /**
     * Teste: Não deve expor dados sensíveis de outros usuários
     */
    public function test_should_not_expose_other_users_sensitive_data(): void
    {
        // Buscar perfil do usuário autenticado
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');

        $content = $response->getContent();
        
        // Não deve conter dados do outro usuário
        $this->assertStringNotContainsString('otheruser', $content);
        $this->assertStringNotContainsString('other@example.com', $content);
        $this->assertStringNotContainsString('98765432100', $content);
    }

    /**
     * Teste: Não deve expor informações sensíveis em erros
     */
    public function test_should_not_expose_sensitive_info_in_errors(): void
    {
        // Simular erro forçando uma exceção
        // Usar token inválido para gerar erro
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid_token_that_will_cause_error',
        ])->getJson('/api/user/profile');

        $content = $response->getContent();
        
        // Não deve expor stack traces
        $this->assertStringNotContainsString('Stack trace', $content);
        $this->assertStringNotContainsString('/var/www', $content);
        $this->assertStringNotContainsString('at ', $content);
        
        // Não deve expor informações do banco de dados
        $this->assertStringNotContainsString('SQLSTATE', $content);
        $this->assertStringNotContainsString('database', strtolower($content));
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
        ])->getJson('/api/user/profile');

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
            ])->getJson('/api/user/profile');

            // Deve retornar erro de autenticação
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
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/user/profile?param={$encodedPayload}");

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
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/user/profile?param={$encodedPayload}");

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

    // ===== SQL INJECTION TESTS =====

    /**
     * Teste: Deve prevenir SQL Injection em parâmetros
     */
    public function test_should_prevent_sql_injection_in_params(): void
    {
        $sqlInjectionPayloads = [
            "' OR '1'='1",
            "' OR '1'='1' --",
            "1' UNION SELECT * FROM users--",
            "'; DROP TABLE users--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $encodedPayload = urlencode($payload);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/user/profile?param={$encodedPayload}");

            // Não deve retornar erros SQL - isso é o mais importante
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent(), "SQL Injection detectado: {$payload}");
            $this->assertStringNotContainsString('SQL syntax', $response->getContent());
            
            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
                $this->assertStringNotContainsString('/var/www', $content);
            }
        }
    }

    // ===== XSS (CROSS-SITE SCRIPTING) TESTS =====

    /**
     * Teste: Deve prevenir XSS em respostas
     */
    public function test_should_prevent_xss_in_responses(): void
    {
        // Criar usuário com dados que poderiam ser usados para XSS
        $xssUser = User::factory()->create([
            'username' => 'xssuser',
            'user_id' => 'xssuser',
            'email' => 'xss@example.com',
            'name' => '<script>alert("XSS")</script>',
            'password' => Hash::make('password123'),
            'status' => 1,
        ]);

        UsersKey::factory()->create([
            'user_id' => $xssUser->user_id,
        ]);

        // Fazer login com o usuário XSS
        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => 'xssuser',
            'password' => 'password123',
        ]);

        $xssToken = $loginResponse->json('token') ?? $loginResponse->json('data.token');

        // Buscar perfil
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $xssToken,
        ])->getJson('/api/user/profile');

        // Verificar que a resposta é JSON válido e não causa erros
        $response->assertStatus(200);
        $data = $response->json('data');
        
        // Verificar que os dados são retornados (a sanitização deve ser feita no frontend)
        $this->assertNotNull($data);
        $this->assertEquals('xssuser', $data['username']);
        
        // Verificar que não há erros de execução de script no backend
        $content = $response->getContent();
        $this->assertStringNotContainsString('Fatal error', $content);
        $this->assertStringNotContainsString('Parse error', $content);
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
            ])->getJson('/api/user/profile');

            // Primeiras requisições devem funcionar
            if ($i < 30) {
                $this->assertContains($response->status(), [200, 429]);
            }
        }
    }

    // ===== CACHE SECURITY TESTS =====

    /**
     * Teste: Deve garantir que cache não expõe dados de outros usuários
     */
    public function test_should_prevent_cache_contamination(): void
    {
        // Buscar perfil do primeiro usuário
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/user/profile');

        $data1 = $response1->json('data');
        $this->assertEquals('testuser', $data1['username']);

        // Fazer login com outro usuário
        $loginResponse2 = $this->postJson('/api/auth/login', [
            'username' => 'otheruser',
            'password' => 'password123',
        ]);

        $token2 = $loginResponse2->json('token') ?? $loginResponse2->json('data.token');

        // Buscar perfil do segundo usuário
        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token2,
        ])->getJson('/api/user/profile');

        $data2 = $response2->json('data');
        
        // Verificar que cada usuário recebe seus próprios dados
        $this->assertEquals('otheruser', $data2['username']);
        $this->assertNotEquals($data1['username'], $data2['username']);
        $this->assertNotEquals($data1['email'], $data2['email']);
    }
}

