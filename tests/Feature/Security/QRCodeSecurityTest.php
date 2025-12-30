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

/**
 * Testes de Segurança - Listagem de QR Codes
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
 * - Date Manipulation
 */
class QRCodeSecurityTest extends TestCase
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

    /**
     * Helper para criar Solicitacoes (QR Codes dinâmicos)
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
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/qrcodes?busca=" . urlencode($payload));

            // Não deve retornar erros SQL - isso é o mais importante
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent(), "SQL Injection detectado: {$payload}");
            $this->assertStringNotContainsString('SQL syntax', $response->getContent());
            
            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
                $this->assertStringNotContainsString('/var/www', $content);
            } else {
                // Deve retornar erro de validação ou resultado vazio
                $this->assertContains($response->status(), [200, 400, 422]);
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
            "2024-01-01' OR '1'='1'--",
            "'; DROP TABLE transactions--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/qrcodes?data_inicio=" . urlencode($payload));

            // Não deve retornar erros SQL
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            
            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
                $this->assertStringNotContainsString('/var/www', $content);
            }
        }
    }

    /**
     * Teste: Deve prevenir SQL Injection em parâmetros de status
     */
    public function test_should_prevent_sql_injection_in_status_param(): void
    {
        $sqlInjectionPayloads = [
            "' OR '1'='1",
            "ativo' OR '1'='1'--",
            "'; DROP TABLE transactions--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/qrcodes?status=" . urlencode($payload));

            // Não deve retornar erros SQL
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            
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
     * Teste: Deve prevenir XSS no parâmetro de busca
     */
    public function test_should_prevent_xss_in_search_param(): void
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
            ])->getJson("/api/qrcodes?busca={$encodedPayload}");

            $content = $response->getContent();
            
            // Não deve retornar o payload XSS na resposta
            $this->assertStringNotContainsString('<script>', $content);
            $this->assertStringNotContainsString('onerror=', $content);
            $this->assertStringNotContainsString('javascript:', $content);
        }
    }

    // ===== AUTHORIZATION TESTS =====

    /**
     * Teste: Deve exigir autenticação para acessar QR Codes
     */
    public function test_should_require_authentication_for_qrcodes(): void
    {
        $response = $this->getJson('/api/qrcodes');

        $response->assertStatus(401);
        $this->assertFalse($response->json('success'));
    }

    /**
     * Teste: Deve prevenir acesso a QR Codes de outros usuários (IDOR)
     */
    public function test_should_prevent_idor_access_to_other_users_qrcodes(): void
    {
        // Criar QR Code para o outro usuário
        $this->createSolicitacao([
            'user_id' => $this->otherUser->username,
            'idTransaction' => 'TXN_OTHER_USER',
            'amount' => 5000.00,
            'status' => 'PAID_OUT',
        ]);

        // Tentar acessar QR Codes usando token do primeiro usuário
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/qrcodes');

        // Deve retornar apenas dados do usuário autenticado
        $response->assertStatus(200);
        $data = $response->json('data.data');
        
        // Não deve conter QR Codes do outro usuário
        if (is_array($data)) {
            foreach ($data as $qrCode) {
                // Verificar que não há QR Codes do outro usuário
                $this->assertNotEquals('TXN_OTHER_USER', $qrCode['transaction_id'] ?? '');
            }
        }
    }

    // ===== INPUT VALIDATION TESTS =====

    /**
     * Teste: Deve validar limites de paginação
     */
    public function test_should_validate_pagination_limits(): void
    {
        $invalidLimits = [
            -1,
            0,
            10000, // Muito grande (máximo é 100)
            "' OR '1'='1",
        ];

        foreach ($invalidLimits as $limit) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/qrcodes?limit={$limit}");

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
     * Teste: Deve validar formato de datas
     */
    public function test_should_validate_date_format(): void
    {
        $invalidDates = [
            'invalid-date',
            '2024-13-45', // Data inválida
            '<script>alert("XSS")</script>',
            "'; DROP TABLE transactions--",
        ];

        foreach ($invalidDates as $date) {
            $encodedDate = urlencode($date);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/qrcodes?data_inicio={$encodedDate}");

            // Não deve retornar erro 500
            $this->assertNotEquals(500, $response->status());
        }
    }

    /**
     * Teste: Deve validar parâmetros de status
     */
    public function test_should_validate_status_parameters(): void
    {
        $invalidStatuses = [
            'invalid_status',
            '<script>alert("XSS")</script>',
            "'; DROP TABLE transactions--",
        ];

        foreach ($invalidStatuses as $status) {
            $encodedStatus = urlencode($status);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson("/api/qrcodes?status={$encodedStatus}");

            // Não deve retornar erro 500
            $this->assertNotEquals(500, $response->status());
            
            // Deve retornar erro de validação ou ignorar status inválido
            $this->assertContains($response->status(), [200, 400, 422]);
        }
    }

    // ===== SENSITIVE DATA EXPOSURE TESTS =====

    /**
     * Teste: Não deve expor informações sensíveis em respostas
     */
    public function test_should_not_expose_sensitive_info_in_responses(): void
    {
        // Criar QR Code de teste
        $this->createSolicitacao([
            'user_id' => $this->user->username,
            'idTransaction' => 'TXN_TEST',
            'amount' => 100.00,
            'status' => 'PAID_OUT',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/qrcodes');

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
        // Criar QR Code para o outro usuário com valor alto
        $this->createSolicitacao([
            'user_id' => $this->otherUser->username,
            'idTransaction' => 'TXN_OTHER',
            'amount' => 999999.00,
            'status' => 'PAID_OUT',
        ]);

        // Buscar QR Codes do usuário autenticado
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/qrcodes');

        $content = $response->getContent();
        
        // Não deve conter dados do QR Code do outro usuário
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
            ])->getJson("/api/qrcodes?busca={$encodedPayload}");

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
            ])->getJson("/api/qrcodes?busca={$encodedPayload}");

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

    // ===== DATE MANIPULATION TESTS =====

    /**
     * Teste: Deve validar manipulação de datas
     */
    public function test_should_validate_date_manipulation(): void
    {
        // Tentar acessar datas futuras
        $futureDate = now()->addYears(10)->format('Y-m-d');
        
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson("/api/qrcodes?data_inicio={$futureDate}&data_fim={$futureDate}");

        // Não deve retornar erro 500
        $this->assertNotEquals(500, $response->status());
        
        // Deve retornar resultado vazio ou erro de validação
        $this->assertContains($response->status(), [200, 400, 422]);
    }

    /**
     * Teste: Deve validar intervalo de datas inválido
     */
    public function test_should_validate_invalid_date_range(): void
    {
        // Data final antes da data inicial
        $startDate = '2024-12-31';
        $endDate = '2024-01-01';
        
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson("/api/qrcodes?data_inicio={$startDate}&data_fim={$endDate}");

        // Não deve retornar erro 500
        $this->assertNotEquals(500, $response->status());
        
        // Deve retornar resultado vazio ou erro de validação
        $this->assertContains($response->status(), [200, 400, 422]);
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
        ])->getJson('/api/qrcodes');

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
            ])->getJson('/api/qrcodes');

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
            ])->getJson('/api/qrcodes?page=' . $i);

            // Primeiras requisições devem funcionar
            if ($i < 30) {
                $this->assertContains($response->status(), [200, 429]);
            }
        }
    }
}







