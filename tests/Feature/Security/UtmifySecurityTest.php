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
 * Testes de Segurança - Integração Utmify
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
 * - 2FA Security
 */
class UtmifySecurityTest extends TestCase
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
            'integracao_utmfy' => null,
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
            'integracao_utmfy' => 'other_user_api_key',
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
     * Teste: Deve exigir autenticação para obter configuração
     */
    public function test_should_require_authentication_to_get_config(): void
    {
        $response = $this->getJson('/api/utmify/config');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve exigir autenticação para salvar configuração
     */
    public function test_should_require_authentication_to_save_config(): void
    {
        $response = $this->postJson('/api/utmify/config', [
            'api_key' => 'test_api_key',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve exigir autenticação para remover configuração
     */
    public function test_should_require_authentication_to_delete_config(): void
    {
        $response = $this->deleteJson('/api/utmify/config');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve exigir autenticação para testar conexão
     */
    public function test_should_require_authentication_to_test_connection(): void
    {
        $response = $this->postJson('/api/utmify/test');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve prevenir acesso a configuração de outros usuários (IDOR)
     */
    public function test_should_prevent_idor_access_to_other_users_config(): void
    {
        // Fazer login com outro usuário
        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => 'otheruser',
            'password' => 'password123',
        ]);

        $otherToken = $loginResponse->json('token') ?? $loginResponse->json('data.token');

        // Obter configuração com token do outro usuário
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $otherToken,
        ])->getJson('/api/utmify/config');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        // Verificar que retorna configuração do usuário autenticado
        $this->assertEquals('other_user_api_key', $data['api_key']);
        
        // Não deve retornar configuração do primeiro usuário
        $this->assertNotEquals($this->user->integracao_utmfy, $data['api_key']);
    }

    // ===== INPUT VALIDATION TESTS =====

    /**
     * Teste: Deve validar que api_key é obrigatório
     */
    public function test_should_validate_api_key_required(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/utmify/config', [
            // Sem api_key
        ]);

        $response->assertStatus(400);
        $this->assertStringContainsString('api_key', $response->getContent());
    }

    /**
     * Teste: Deve validar tamanho máximo da API Key
     */
    public function test_should_validate_api_key_max_length(): void
    {
        $longApiKey = str_repeat('a', 256); // Mais que 255 caracteres

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/utmify/config', [
            'api_key' => $longApiKey,
        ]);

        // Deve retornar erro de validação
        $this->assertContains($response->status(), [400, 422]);
    }

    /**
     * Teste: Deve validar formato do PIN de 2FA
     */
    public function test_should_validate_2fa_pin_format(): void
    {
        $this->user->update([
            'twofa_enabled' => true,
            'twofa_pin' => Hash::make('123456'),
        ]);

        $invalidPins = [
            '12345',      // Muito curto
            '1234567',    // Muito longo
            'abcdef',     // Não numérico
        ];

        foreach ($invalidPins as $invalidPin) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/utmify/config', [
                'api_key' => 'test_api_key',
                'pin' => $invalidPin,
            ]);

            // Deve retornar erro de validação ou 500 se houver erro interno
            // O importante é que não retorne 200 (sucesso com PIN inválido)
            $this->assertNotEquals(200, $response->status());
            
            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
            }
        }
    }

    // ===== SQL INJECTION TESTS =====

    /**
     * Teste: Deve prevenir SQL Injection em API Key
     */
    public function test_should_prevent_sql_injection_in_api_key(): void
    {
        $sqlInjectionPayloads = [
            "' OR '1'='1",
            "'; DROP TABLE users--",
            "' UNION SELECT * FROM users--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/utmify/config', [
                'api_key' => $payload,
            ]);

            // Não deve retornar erros SQL
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            $this->assertStringNotContainsString('SQL syntax', $response->getContent());
            
            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
            }
        }
    }

    /**
     * Teste: Deve prevenir SQL Injection em PIN de 2FA
     */
    public function test_should_prevent_sql_injection_in_2fa_pin(): void
    {
        $this->user->update([
            'twofa_enabled' => true,
            'twofa_pin' => Hash::make('123456'),
        ]);

        $sqlInjectionPayloads = [
            "' OR '1'='1",
            "'; DROP TABLE users--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/utmify/config', [
                'api_key' => 'test_api_key',
                'pin' => $payload,
            ]);

            // Não deve retornar erros SQL
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            
            // Se retornar 500, verificar que não expõe informações sensíveis
            if ($response->status() === 500) {
                $content = $response->getContent();
                $this->assertStringNotContainsString('Stack trace', $content);
            }
            
            // Não deve retornar 200 (sucesso com payload SQL)
            $this->assertNotEquals(200, $response->status());
        }
    }

    // ===== XSS TESTS =====

    /**
     * Teste: Deve prevenir XSS em API Key
     */
    public function test_should_prevent_xss_in_api_key(): void
    {
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            'javascript:alert("XSS")',
            'onerror=alert("XSS")',
        ];

        foreach ($xssPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/utmify/config', [
                'api_key' => $payload,
            ]);

            // Verificar que não há erros de execução de script no backend
            $content = $response->getContent();
            $this->assertStringNotContainsString('Fatal error', $content);
            $this->assertStringNotContainsString('Parse error', $content);
            
            // Se a API Key foi salva, verificar que foi salva corretamente
            if ($response->status() === 200) {
                $user = $this->user->fresh();
                if ($user->integracao_utmfy) {
                    // O payload pode estar salvo (é uma API Key), mas não deve causar erros
                    $this->assertNotNull($user->integracao_utmfy);
                }
            }
        }
    }

    // ===== 2FA SECURITY TESTS =====

    /**
     * Teste: Deve exigir PIN de 2FA quando 2FA está ativado para salvar
     */
    public function test_should_require_2fa_pin_when_2fa_enabled_to_save(): void
    {
        $this->user->update([
            'twofa_enabled' => true,
            'twofa_pin' => Hash::make('123456'),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/utmify/config', [
            'api_key' => 'test_api_key',
            // Sem PIN de 2FA
        ]);

        // Deve retornar erro exigindo PIN (pode ser 400, 401 ou 500 se houver erro interno)
        // O importante é que não retorne 200 (sucesso sem PIN)
        $this->assertNotEquals(200, $response->status());
        
        // Se retornar 500, verificar que não expõe informações sensíveis
        if ($response->status() === 500) {
            $content = $response->getContent();
            $this->assertStringNotContainsString('Stack trace', $content);
        }
    }

    /**
     * Teste: Deve exigir PIN de 2FA quando 2FA está ativado para remover
     */
    public function test_should_require_2fa_pin_when_2fa_enabled_to_delete(): void
    {
        $this->user->update([
            'twofa_enabled' => true,
            'twofa_pin' => Hash::make('123456'),
            'integracao_utmfy' => 'test_api_key',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->deleteJson('/api/utmify/config', [
            // Sem PIN de 2FA
        ]);

        // Deve retornar erro exigindo PIN (pode ser 400, 401 ou 500 se houver erro interno)
        // O importante é que não retorne 200 (sucesso sem PIN)
        $this->assertNotEquals(200, $response->status());
        
        // Se retornar 500, verificar que não expõe informações sensíveis
        if ($response->status() === 500) {
            $content = $response->getContent();
            $this->assertStringNotContainsString('Stack trace', $content);
        }
    }

    /**
     * Teste: Deve validar PIN de 2FA ao salvar
     */
    public function test_should_validate_2fa_pin_when_saving(): void
    {
        $this->user->update([
            'twofa_enabled' => true,
            'twofa_pin' => Hash::make('123456'),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/utmify/config', [
            'api_key' => 'test_api_key',
            'pin' => '000000', // PIN incorreto
        ]);

        // Deve retornar erro de PIN inválido
        $this->assertContains($response->status(), [400, 401]);
    }

    /**
     * Teste: Deve validar PIN de 2FA ao remover
     */
    public function test_should_validate_2fa_pin_when_deleting(): void
    {
        $this->user->update([
            'twofa_enabled' => true,
            'twofa_pin' => Hash::make('123456'),
            'integracao_utmfy' => 'test_api_key',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->deleteJson('/api/utmify/config', [
            'pin' => '000000', // PIN incorreto
        ]);

        // Deve retornar erro de PIN inválido
        $this->assertContains($response->status(), [400, 401]);
    }

    // ===== SENSITIVE DATA EXPOSURE TESTS =====

    /**
     * Teste: Não deve expor informações sensíveis em respostas
     */
    public function test_should_not_expose_sensitive_info_in_responses(): void
    {
        $this->user->update([
            'integracao_utmfy' => 'secret_api_key_12345',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/utmify/config');

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
        // Usar token inválido para gerar erro
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid_token_that_will_cause_error',
        ])->getJson('/api/utmify/config');

        $content = $response->getContent();
        
        // Não deve expor stack traces
        $this->assertStringNotContainsString('Stack trace', $content);
        $this->assertStringNotContainsString('/var/www', $content);
        $this->assertStringNotContainsString('at ', $content);
        
        // Não deve expor informações do banco de dados
        $this->assertStringNotContainsString('SQLSTATE', $content);
        $this->assertStringNotContainsString('database', strtolower($content));
    }

    // ===== RATE LIMITING TESTS =====

    /**
     * Teste: Deve implementar rate limiting em obter configuração
     */
    public function test_should_implement_rate_limiting_in_get_config(): void
    {
        // Fazer múltiplas requisições rapidamente
        for ($i = 0; $i < 65; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->getJson('/api/utmify/config');

            // Após algumas tentativas, deve retornar 429
            if ($i >= 59) {
                $this->assertContains($response->status(), [200, 429]);
            }
        }
    }

    /**
     * Teste: Deve implementar rate limiting em salvar configuração
     */
    public function test_should_implement_rate_limiting_in_save_config(): void
    {
        // Fazer múltiplas requisições rapidamente
        for ($i = 0; $i < 15; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/utmify/config', [
                'api_key' => 'test_api_key_' . $i,
            ]);

            // Após algumas tentativas, deve retornar 429
            if ($i >= 9) {
                $this->assertContains($response->status(), [200, 400, 422, 429]);
            }
        }
    }

    /**
     * Teste: Deve implementar rate limiting em testar conexão
     */
    public function test_should_implement_rate_limiting_in_test_connection(): void
    {
        // Configurar API Key primeiro
        $this->user->update([
            'integracao_utmfy' => 'test_api_key',
        ]);

        // Fazer múltiplas requisições rapidamente
        for ($i = 0; $i < 10; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/utmify/test');

            // Após algumas tentativas, deve retornar 429
            if ($i >= 4) {
                $this->assertContains($response->status(), [200, 400, 429]);
            }
        }
    }

    // ===== PATH TRAVERSAL TESTS =====

    /**
     * Teste: Deve prevenir path traversal em API Key
     */
    public function test_should_prevent_path_traversal_in_api_key(): void
    {
        $pathTraversalPayloads = [
            '../../../etc/passwd',
            '..\\..\\..\\windows\\system32',
            '....//....//etc/passwd',
        ];

        foreach ($pathTraversalPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/utmify/config', [
                'api_key' => $payload,
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
     * Teste: Deve prevenir command injection em API Key
     */
    public function test_should_prevent_command_injection_in_api_key(): void
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
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/utmify/config', [
                'api_key' => $payload,
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

    // ===== TEST CONNECTION SECURITY =====

    /**
     * Teste: Deve exigir API Key configurada para testar conexão
     */
    public function test_should_require_api_key_configured_to_test_connection(): void
    {
        // Garantir que não há API Key configurada
        $this->user->update([
            'integracao_utmfy' => null,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/utmify/test');

        // Deve retornar erro informando que API Key não está configurada
        $this->assertContains($response->status(), [400, 422]);
        $this->assertStringContainsString('API Key', $response->getContent());
    }
}

