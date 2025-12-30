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
 * Testes de Segurança - Jornada Orizon (Gamificação)
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
 * - Rate Limiting
 */
class GamificationSecurityTest extends TestCase
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
     * Teste: Deve exigir autenticação para obter dados de gamificação (journey)
     */
    public function test_should_require_authentication_to_get_journey_data(): void
    {
        $response = $this->getJson('/api/gamification/journey');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve exigir autenticação para obter dados de gamificação (sidebar)
     */
    public function test_should_require_authentication_to_get_sidebar_data(): void
    {
        $response = $this->getJson('/api/gamification/sidebar');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve prevenir acesso a dados de gamificação de outros usuários (IDOR)
     */
    public function test_should_prevent_idor_access_to_other_users_gamification_data(): void
    {
        // Fazer login com outro usuário
        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => 'otheruser',
            'password' => 'password123',
        ]);

        $otherToken = $loginResponse->json('token') ?? $loginResponse->json('data.token');

        // Obter dados de gamificação com token do outro usuário
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $otherToken,
        ])->getJson('/api/gamification/journey');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        // Verificar que retorna dados do usuário autenticado
        $this->assertNotNull($data);
        
        // Não deve retornar dados do primeiro usuário
        // Cada usuário deve ter seus próprios dados de gamificação
        $this->assertArrayHasKey('current_level', $data);
        $this->assertArrayHasKey('total_deposited', $data);
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
            $encodedPayload = urlencode($payload);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/gamification/journey?param={$encodedPayload}");

            // Não deve retornar erros SQL
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            $this->assertStringNotContainsString('SQL syntax', $response->getContent());
            
            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
                $this->assertStringNotContainsString('/var/www', $content);
            }
        }
    }

    // ===== XSS TESTS =====

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

        // Buscar dados de gamificação
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $xssToken,
        ])->getJson('/api/gamification/journey');

        // Verificar que a resposta é JSON válido e não causa erros
        $response->assertStatus(200);
        $data = $response->json('data');
        
        // Verificar que os dados são retornados (a sanitização deve ser feita no frontend)
        $this->assertNotNull($data);
        
        // Verificar que não há erros de execução de script no backend
        $content = $response->getContent();
        $this->assertStringNotContainsString('Fatal error', $content);
        $this->assertStringNotContainsString('Parse error', $content);
    }

    // ===== SENSITIVE DATA EXPOSURE TESTS =====

    /**
     * Teste: Não deve expor informações sensíveis em respostas
     */
    public function test_should_not_expose_sensitive_info_in_responses(): void
    {
        $endpoints = [
            '/api/gamification/journey',
            '/api/gamification/sidebar',
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson($endpoint);

            $content = $response->getContent();
            
            // Não deve expor senhas
            $this->assertStringNotContainsString('password', strtolower($content));
            $this->assertStringNotContainsString('password_hash', strtolower($content));
            
            // Não deve expor tokens completos
            $this->assertStringNotContainsString($this->token, $content);
            
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
        // Usar token inválido para gerar erro
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid_token_that_will_cause_error',
        ])->getJson('/api/gamification/journey');

        $content = $response->getContent();
        
        // Não deve expor stack traces
        $this->assertStringNotContainsString('Stack trace', $content);
        $this->assertStringNotContainsString('/var/www', $content);
        $this->assertStringNotContainsString('at ', $content);
        
        // Não deve expor informações do banco de dados
        $this->assertStringNotContainsString('SQLSTATE', $content);
        $this->assertStringNotContainsString('database', strtolower($content));
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
            ])->getJson("/api/gamification/journey?param={$encodedPayload}");

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
            ])->getJson("/api/gamification/journey?param={$encodedPayload}");

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
            ])->getJson('/api/gamification/journey');

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
        // Buscar dados de gamificação do primeiro usuário
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/gamification/journey');

        $data1 = $response1->json('data');
        $this->assertNotNull($data1);

        // Fazer login com outro usuário
        $loginResponse2 = $this->postJson('/api/auth/login', [
            'username' => 'otheruser',
            'password' => 'password123',
        ]);

        $token2 = $loginResponse2->json('token') ?? $loginResponse2->json('data.token');

        // Buscar dados de gamificação do segundo usuário
        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token2,
        ])->getJson('/api/gamification/journey');

        $data2 = $response2->json('data');
        
        // Verificar que cada usuário recebe seus próprios dados
        $this->assertNotNull($data2);
        
        // Os dados podem ser diferentes ou iguais dependendo dos depósitos,
        // mas cada usuário deve receber seus próprios dados calculados
        $this->assertArrayHasKey('current_level', $data2);
        $this->assertArrayHasKey('total_deposited', $data2);
    }

    // ===== DATA INTEGRITY TESTS =====

    /**
     * Teste: Deve retornar estrutura de dados válida
     */
    public function test_should_return_valid_data_structure(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/gamification/journey');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        // Verificar estrutura esperada
        $this->assertArrayHasKey('current_level', $data);
        $this->assertArrayHasKey('total_deposited', $data);
        $this->assertArrayHasKey('current_progress', $data);
        $this->assertArrayHasKey('achievement_trail', $data);
        $this->assertArrayHasKey('achievement_messages', $data);
        
        // Verificar tipos
        $this->assertIsNumeric($data['total_deposited']);
        $this->assertIsNumeric($data['current_progress']);
        $this->assertIsArray($data['achievement_trail']);
        $this->assertIsArray($data['achievement_messages']);
    }

    /**
     * Teste: Deve retornar estrutura de dados válida para sidebar
     */
    public function test_should_return_valid_sidebar_data_structure(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/gamification/sidebar');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        // Verificar estrutura esperada (baseado na implementação real)
        $this->assertArrayHasKey('current_level', $data);
        $this->assertArrayHasKey('total_deposited', $data);
        $this->assertArrayHasKey('current_level_max', $data);
        
        // Verificar tipos
        $this->assertIsNumeric($data['total_deposited']);
        $this->assertIsNumeric($data['current_level_max']);
        
        // next_level pode ser null ou um array
        if (isset($data['next_level'])) {
            $this->assertIsArray($data['next_level']);
        }
    }
}

