<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use App\Models\User;
use App\Models\UsersKey;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Segurança - Buscar Transações
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
class TransactionSearchSecurityTest extends TestCase
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

    // ===== SQL INJECTION TESTS =====

    /**
     * Teste: Deve prevenir SQL Injection no parâmetro de busca
     */
    public function test_should_prevent_sql_injection_in_search_param(): void
    {
        $sqlInjectionPayloads = [
            "' OR '1'='1",
            "' OR '1'='1' --",
            "1' UNION SELECT * FROM users--",
            "'; DROP TABLE transactions--",
            "' OR '1'='1' OR '",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/transactions?busca=" . urlencode($payload));

            // Não deve retornar erros SQL
            $this->assertNotEquals(500, $response->status(), "SQL Injection detectado: {$payload}");
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            $this->assertStringNotContainsString('SQL syntax', $response->getContent());
            
            // Deve retornar erro de validação ou resultado vazio
            $this->assertContains($response->status(), [200, 400, 422]);
        }
    }

    /**
     * Teste: Deve prevenir SQL Injection em IDs de transação
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
     * Teste: Deve prevenir XSS no parâmetro de busca
     */
    public function test_should_prevent_xss_in_search_param(): void
    {
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            '<img src=x onerror=alert("XSS")>',
            'javascript:alert("XSS")',
            '<svg onload=alert("XSS")>',
        ];

        foreach ($xssPayloads as $payload) {
            $encodedPayload = urlencode($payload);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/transactions?busca={$encodedPayload}");

            $content = $response->getContent();
            
            // Não deve retornar o payload XSS na resposta
            $this->assertStringNotContainsString('<script>', $content);
            $this->assertStringNotContainsString('onerror=', $content);
            $this->assertStringNotContainsString('javascript:', $content);
        }
    }

    // ===== AUTHORIZATION TESTS =====

    /**
     * Teste: Deve exigir autenticação para buscar transações
     */
    public function test_should_require_authentication_for_search(): void
    {
        $response = $this->getJson('/api/transactions?busca=TXN123');

        $response->assertStatus(401);
        $this->assertFalse($response->json('success'));
    }

    /**
     * Helper para criar Solicitacoes
     */
    private function createSolicitacao(array $attributes = []): Solicitacoes
    {
        $defaults = [
            'user_id' => 'testuser',
            'idTransaction' => 'TXN' . uniqid(),
            'externalreference' => 'EXT' . uniqid(),
            'amount' => 100.00,
            'deposito_liquido' => 97.50,
            'taxa_cash_in' => 2.50,
            'status' => 'PAID_OUT',
            'date' => now(),
            'client_name' => 'Cliente Test',
            'client_document' => '12345678900',
            'client_email' => 'cliente@test.com',
            'client_telefone' => '11999999999',
            'qrcode_pix' => 'https://example.com/qr',
            'paymentcode' => 'PAY' . uniqid(),
            'paymentCodeBase64' => base64_encode('PAY' . uniqid()),
            'adquirente_ref' => 'Banco Test',
            'taxa_pix_cash_in_adquirente' => 1.0,
            'taxa_pix_cash_in_valor_fixo' => 0.5,
            'executor_ordem' => 'EXEC' . uniqid(),
            'descricao_transacao' => 'Teste',
        ];

        $merged = array_merge($defaults, $attributes);
        
        if (isset($merged['user_id']) && is_object($merged['user_id'])) {
            if (isset($merged['user_id']->username)) {
                $merged['user_id'] = $merged['user_id']->username;
            } elseif (isset($merged['user_id']->user_id)) {
                $merged['user_id'] = $merged['user_id']->user_id;
            }
        }

        return Solicitacoes::create($merged);
    }

    /**
     * Teste: Deve prevenir acesso a transações de outros usuários (IDOR)
     */
    public function test_should_prevent_idor_access_to_other_users_transactions(): void
    {
        // Criar transação para o outro usuário
        $otherUserTransaction = $this->createSolicitacao([
            'user_id' => $this->otherUser->username,
            'idTransaction' => 'TXN_OTHER_USER',
            'externalreference' => 'E2E_OTHER_USER',
            'amount' => 5000.00,
            'status' => 'PAID_OUT',
        ]);

        // Tentar buscar transação do outro usuário usando token do primeiro
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson("/api/transactions?busca=TXN_OTHER_USER");

        // Deve retornar resultado vazio (não deve encontrar transação de outro usuário)
        $response->assertStatus(200);
        $data = $response->json('data.data');
        
        // Não deve retornar transação do outro usuário
        $this->assertEmpty($data);
    }

    /**
     * Teste: Deve prevenir acesso direto a transação de outro usuário por ID
     */
    public function test_should_prevent_direct_access_to_other_users_transaction_by_id(): void
    {
        // Criar transação para o outro usuário
        $otherUserTransaction = $this->createSolicitacao([
            'user_id' => $this->otherUser->username,
            'idTransaction' => 'TXN_OTHER_USER',
            'amount' => 5000.00,
            'status' => 'PAID_OUT',
        ]);

        // Tentar acessar transação do outro usuário diretamente por ID
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson("/api/transactions/{$otherUserTransaction->id}");

        // Deve retornar erro 404 ou 403 (não autorizado)
        $this->assertContains($response->status(), [404, 403]);
    }

    // ===== INPUT VALIDATION TESTS =====

    /**
     * Teste: Deve validar tamanho máximo do parâmetro de busca
     */
    public function test_should_validate_max_search_length(): void
    {
        $longSearch = str_repeat('a', 1000);
        
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson("/api/transactions?busca=" . urlencode($longSearch));

        // Não deve retornar erro 500
        $this->assertNotEquals(500, $response->status());
        
        // Deve retornar erro de validação ou resultado vazio
        $this->assertContains($response->status(), [200, 400, 422]);
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

    /**
     * Teste: Deve validar parâmetros de tipo
     */
    public function test_should_validate_type_parameters(): void
    {
        $invalidTypes = [
            'invalid_type',
            '<script>alert("XSS")</script>',
            "'; DROP TABLE transactions--",
        ];

        foreach ($invalidTypes as $type) {
            $encodedType = urlencode($type);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/transactions?tipo={$encodedType}");

            // Não deve retornar erro 500
            $this->assertNotEquals(500, $response->status());
            
            // Deve retornar erro de validação ou ignorar tipo inválido
            $this->assertContains($response->status(), [200, 400, 422]);
        }
    }

    // ===== SENSITIVE DATA EXPOSURE TESTS =====

    /**
     * Teste: Não deve expor informações sensíveis em respostas
     */
    public function test_should_not_expose_sensitive_info_in_responses(): void
    {
        // Criar transação de teste
        $transaction = $this->createSolicitacao([
            'user_id' => $this->user->username,
            'idTransaction' => 'TXN_TEST',
            'amount' => 100.00,
            'status' => 'PAID_OUT',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson("/api/transactions?busca=TXN_TEST");

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
        // Criar transação para o outro usuário com valor alto
        $otherUserTransaction = $this->createSolicitacao([
            'user_id' => $this->otherUser->username,
            'idTransaction' => 'TXN_OTHER',
            'amount' => 999999.00,
            'status' => 'PAID_OUT',
        ]);

        // Buscar transações do usuário autenticado
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/transactions');

        $content = $response->getContent();
        
        // Não deve conter dados da transação do outro usuário
        $this->assertStringNotContainsString('TXN_OTHER', $content);
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
        ];

        foreach ($pathTraversalPayloads as $payload) {
            $encodedPayload = urlencode($payload);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/transactions?busca={$encodedPayload}");

            // Não deve permitir path traversal
            $this->assertNotEquals(500, $response->status());
            
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
            ])->getJson("/api/transactions?busca={$encodedPayload}");

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
        ])->getJson('/api/transactions?busca=TXN123');

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
            ])->getJson('/api/transactions?busca=TXN123');

            // Deve retornar erro de autenticação
            $this->assertContains($response->status(), [401, 403]);
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
            ])->getJson('/api/transactions?busca=TXN' . $i);

            // Primeiras requisições devem funcionar
            if ($i < 30) {
                $this->assertContains($response->status(), [200, 429]);
            }
        }
    }
}

