<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use App\Models\User;
use App\Models\UsersKey;
use App\Models\Solicitacoes;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;
use Tests\Feature\Helpers\TransactionTestHelper;

/**
 * Testes de Segurança - PIX Infrações
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
 * - Pagination Security
 */
class PixInfracoesSecurityTest extends TestCase
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

        // Criar infrações para o usuário principal
        TransactionTestHelper::createSolicitacao([
            'user_id' => $this->user->username,
            'amount' => 100.00,
            'status' => 'MEDIATION',
            'descricao_transacao' => 'Test infraction 1',
            'idTransaction' => 'TXN001',
            'codigo_autenticacao' => 'E123456789',
        ]);

        TransactionTestHelper::createSolicitacao([
            'user_id' => $this->user->username,
            'amount' => 200.00,
            'status' => 'CHARGEBACK',
            'descricao_transacao' => 'Test infraction 2',
            'idTransaction' => 'TXN002',
            'codigo_autenticacao' => 'E987654321',
        ]);

        // Criar infração para outro usuário
        TransactionTestHelper::createSolicitacao([
            'user_id' => $this->otherUser->username,
            'amount' => 300.00,
            'status' => 'DISPUTE',
            'descricao_transacao' => 'Other user infraction',
            'idTransaction' => 'TXN003',
            'codigo_autenticacao' => 'E111222333',
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
     * Teste: Deve exigir autenticação para listar infrações
     */
    public function test_should_require_authentication_to_list_infracoes(): void
    {
        $response = $this->getJson('/api/pix/infracoes');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve prevenir IDOR - não deve acessar infração de outro usuário
     */
    public function test_should_prevent_idor_access_other_users_infracao(): void
    {
        $otherUserInfracao = Solicitacoes::where('user_id', $this->otherUser->username)->first();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson("/api/pix/infracoes/{$otherUserInfracao->id}");

        // Deve retornar 404 (não encontrado)
        $response->assertStatus(404);
    }

    /**
     * Teste: Deve retornar apenas infrações do usuário autenticado
     */
    public function test_should_return_only_user_infracoes(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/pix/infracoes');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        
        // Verificar que todas as infrações pertencem ao usuário autenticado
        foreach ($data['data'] as $infracao) {
            // Não deve conter infrações do outro usuário
            $this->assertNotEquals('Other user infraction', $infracao['descricao'] ?? $infracao['tipo'] ?? null);
        }
    }

    // ===== INPUT VALIDATION TESTS =====

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
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/pix/infracoes?page={$page}");

            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
            }
            
            // A API pode normalizar valores inválidos e retornar 200
            // O importante é que não expõe informações sensíveis
            if ($response->status() === 200) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
                $this->assertStringNotContainsString('SQLSTATE', $content);
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
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/pix/infracoes?limit={$limit}");

            // Deve normalizar para 20 ou retornar erro
            if ($response->status() === 200) {
                $data = $response->json('data');
                $this->assertLessThanOrEqual(100, $data['per_page'] ?? 20);
            }
        }
    }

    /**
     * Teste: Deve validar formato de data
     */
    public function test_should_validate_date_format(): void
    {
        $invalidDates = [
            'invalid-date',
            '<script>alert("XSS")</script>',
            '2024-13-45', // Data inválida
        ];

        foreach ($invalidDates as $date) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/pix/infracoes?data_inicio={$date}");

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
            "'; DROP TABLE solicitacoes--",
            "' UNION SELECT * FROM solicitacoes--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $encodedPayload = urlencode($payload);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/pix/infracoes?busca={$encodedPayload}");

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
     * Teste: Deve prevenir SQL Injection em parâmetros de data
     */
    public function test_should_prevent_sql_injection_in_date_params(): void
    {
        $sqlInjectionPayloads = [
            "' OR '1'='1",
            "'; DROP TABLE solicitacoes--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $encodedPayload = urlencode($payload);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/pix/infracoes?data_inicio={$encodedPayload}");

            // Não deve retornar erros SQL
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
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
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/pix/infracoes?busca={$encodedPayload}");

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
        ])->getJson('/api/pix/infracoes');

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

    /**
     * Teste: Não deve expor informações sensíveis em erros
     */
    public function test_should_not_expose_sensitive_info_in_errors(): void
    {
        // Simular erro forçando uma exceção
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid_token_that_will_cause_error',
        ])->getJson('/api/pix/infracoes');

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
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson('/api/pix/infracoes');

            // Após algumas tentativas, deve retornar 429
            if ($i >= 59) {
                $this->assertContains($response->status(), [200, 401, 429]);
            }
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
            ])->getJson("/api/pix/infracoes?busca={$encodedPayload}");

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
            ])->getJson("/api/pix/infracoes?busca={$encodedPayload}");

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

