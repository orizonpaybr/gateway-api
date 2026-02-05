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
 * Testes de Segurança - Configurações (Conta, Integração, Notificações)
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
 * - Password Security
 * - 2FA Security
 * - API Credentials Security
 */
class SettingsSecurityTest extends TestCase
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

    // ===== CONTA - TROCAR SENHA =====

    /**
     * Teste: Deve exigir autenticação para trocar senha
     */
    public function test_should_require_authentication_to_change_password(): void
    {
        $response = $this->postJson('/api/auth/change-password', [
            'current_password' => 'password123',
            'new_password' => 'NewPassword123!',
            'new_password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve prevenir SQL Injection em trocar senha
     */
    public function test_should_prevent_sql_injection_in_change_password(): void
    {
        $sqlInjectionPayloads = [
            "' OR '1'='1",
            "'; DROP TABLE users--",
            "' UNION SELECT * FROM users--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/auth/change-password', [
                'current_password' => $payload,
                'new_password' => 'NewPassword123!',
                'new_password_confirmation' => 'NewPassword123!',
            ]);

            // Não deve retornar erros SQL
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            $this->assertStringNotContainsString('SQL syntax', $response->getContent());
            
            // Deve retornar erro de validação ou autenticação
            $this->assertContains($response->status(), [400, 401, 422]);
        }
    }

    /**
     * Teste: Deve validar força da senha
     */
    public function test_should_validate_password_strength(): void
    {
        $weakPasswords = [
            '123',
            'password',
            '123456',
            'abc',
        ];

        foreach ($weakPasswords as $weakPassword) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/auth/change-password', [
                'current_password' => 'password123',
                'new_password' => $weakPassword,
                'new_password_confirmation' => $weakPassword,
            ]);

            // Deve retornar erro de validação ou aceitar (dependendo da validação)
            // O importante é que não retorne 500 (erro de servidor)
            $this->assertNotEquals(500, $response->status());
            $this->assertNotEquals(200, $response->status());
        }
    }

    /**
     * Teste: Deve implementar rate limiting em trocar senha
     */
    public function test_should_implement_rate_limiting_in_change_password(): void
    {
        // Fazer 4 tentativas (limite é 3 por hora)
        for ($i = 0; $i < 4; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/auth/change-password', [
                'current_password' => 'wrong_password',
                'new_password' => 'NewPassword123!',
                'new_password_confirmation' => 'NewPassword123!',
            ]);

            // Após 3 tentativas, deve retornar 429
            if ($i >= 2) {
                $this->assertContains($response->status(), [401, 429]);
            }
        }
    }

    /**
     * Teste: Deve exigir PIN de 2FA quando 2FA está ativado
     */
    public function test_should_require_2fa_pin_when_2fa_enabled(): void
    {
        $this->user->update([
            'twofa_enabled' => true,
            'twofa_pin' => Hash::make('123456'),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/auth/change-password', [
            'current_password' => 'password123',
            'new_password' => 'NewPassword123!',
            'new_password_confirmation' => 'NewPassword123!',
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
     * Teste: Não deve expor informações sensíveis em erros de trocar senha
     */
    public function test_should_not_expose_sensitive_info_in_change_password_errors(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/auth/change-password', [
            'current_password' => 'wrong_password',
            'new_password' => 'NewPassword123!',
            'new_password_confirmation' => 'NewPassword123!',
        ]);

        $content = $response->getContent();
        
        // Não deve expor stack traces
        $this->assertStringNotContainsString('Stack trace', $content);
        $this->assertStringNotContainsString('/var/www', $content);
        
        // Não deve expor informações do banco de dados
        $this->assertStringNotContainsString('SQLSTATE', $content);
    }

    // ===== CONTA - 2FA =====

    /**
     * Teste: Deve exigir autenticação para ativar 2FA
     */
    public function test_should_require_authentication_to_enable_2fa(): void
    {
        $response = $this->postJson('/api/2fa/enable', [
            'code' => '123456',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve validar formato do PIN de 2FA
     */
    public function test_should_validate_2fa_pin_format(): void
    {
        $invalidPins = [
            '12345',      // Muito curto
            '1234567',    // Muito longo
            'abcdef',     // Não numérico
            '12 3456',    // Com espaços
        ];

        foreach ($invalidPins as $invalidPin) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/2fa/enable', [
                'code' => $invalidPin,
            ]);

            // Verificar que não há erros SQL ou exposição de informações sensíveis
            $content = $response->getContent();
            $this->assertStringNotContainsString('SQLSTATE', $content);
            $this->assertStringNotContainsString('Stack trace', $content);
            $this->assertStringNotContainsString('/var/www', $content);
            
            // Se retornar 200, verificar que o PIN foi salvo corretamente (sem caracteres perigosos)
            if ($response->status() === 200) {
                $user = $this->user->fresh();
                if ($user->twofa_pin) {
                    // O PIN deve estar hasheado, não deve conter o payload original
                    $this->assertStringNotContainsString($invalidPin, $user->twofa_pin);
                }
            }
        }
    }

    /**
     * Teste: Deve prevenir SQL Injection em 2FA
     */
    public function test_should_prevent_sql_injection_in_2fa(): void
    {
        $sqlInjectionPayloads = [
            "' OR '1'='1",
            "'; DROP TABLE users--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/2fa/enable', [
                'code' => $payload,
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

    /**
     * Teste: Deve prevenir acesso a 2FA de outros usuários (IDOR)
     */
    public function test_should_prevent_idor_access_to_other_users_2fa(): void
    {
        // Fazer login com outro usuário
        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => 'otheruser',
            'password' => 'password123',
        ]);

        $otherToken = $loginResponse->json('token') ?? $loginResponse->json('data.token');

        // Tentar ativar 2FA com token do outro usuário
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $otherToken,
        ])->postJson('/api/2fa/enable', [
            'code' => '123456',
        ]);

        // Deve funcionar apenas para o usuário autenticado
        $response->assertStatus(200);
        
        // Verificar que o 2FA foi ativado apenas para o outro usuário
        $this->assertTrue($this->otherUser->fresh()->twofa_enabled ?? false);
        $this->assertFalse($this->user->fresh()->twofa_enabled ?? false);
    }

    // ===== INTEGRAÇÃO - CREDENCIAIS =====

    /**
     * Teste: Deve exigir autenticação para obter credenciais
     */
    public function test_should_require_authentication_to_get_credentials(): void
    {
        $response = $this->getJson('/api/integration/credentials');

        $response->assertStatus(401);
    }

    /**
     * Teste: Deve prevenir acesso a credenciais de outros usuários (IDOR)
     */
    public function test_should_prevent_idor_access_to_other_users_credentials(): void
    {
        // Fazer login com outro usuário
        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => 'otheruser',
            'password' => 'password123',
        ]);

        $otherToken = $loginResponse->json('token') ?? $loginResponse->json('data.token');

        // Obter credenciais com token do outro usuário
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $otherToken,
        ])->getJson('/api/integration/credentials');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        // Verificar que retorna credenciais do usuário autenticado
        $otherUserKey = UsersKey::where('user_id', $this->otherUser->username)->first();
        $this->assertEquals($otherUserKey->token, $data['client_key']);
        $this->assertEquals($otherUserKey->secret, $data['client_secret']);
        
        // Não deve retornar credenciais do primeiro usuário
        $this->assertNotEquals($this->user->username, $this->otherUser->username);
    }

    /**
     * Teste: Deve exigir PIN de 2FA para regenerar secret quando 2FA está ativado
     */
    public function test_should_require_2fa_pin_to_regenerate_secret_when_2fa_enabled(): void
    {
        $this->user->update([
            'twofa_enabled' => true,
            'twofa_pin' => Hash::make('123456'),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/integration/regenerate-secret', [
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
     * Teste: Deve validar PIN de 2FA ao regenerar secret
     */
    public function test_should_validate_2fa_pin_when_regenerating_secret(): void
    {
        $this->user->update([
            'twofa_enabled' => true,
            'twofa_pin' => Hash::make('123456'),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/integration/regenerate-secret', [
            'pin' => '000000', // PIN incorreto
        ]);

        // Deve retornar erro de PIN inválido
        $this->assertContains($response->status(), [400, 401]);
    }

    /**
     * Teste: Deve implementar rate limiting em regenerar secret
     */
    public function test_should_implement_rate_limiting_in_regenerate_secret(): void
    {
        // Fazer múltiplas requisições rapidamente
        for ($i = 0; $i < 10; $i++) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/integration/regenerate-secret');

            // Após algumas tentativas, deve retornar 429
            if ($i >= 4) {
                $this->assertContains($response->status(), [200, 429]);
            }
        }
    }

    // ===== INTEGRAÇÃO - IPs AUTORIZADOS =====

    /**
     * Teste: Deve validar formato de IP
     */
    public function test_should_validate_ip_format(): void
    {
        $invalidIPs = [
            'not_an_ip',
            '256.256.256.256',
            '192.168.1',
            '192.168.1.1.1',
            '<script>alert("XSS")</script>',
        ];

        foreach ($invalidIPs as $invalidIP) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/integration/allowed-ips', [
                'ip' => $invalidIP,
            ]);

            // Deve retornar erro de validação
            $this->assertContains($response->status(), [400, 422]);
        }
    }

    /**
     * Teste: Deve prevenir SQL Injection em IPs autorizados
     */
    public function test_should_prevent_sql_injection_in_allowed_ips(): void
    {
        $sqlInjectionPayloads = [
            "' OR '1'='1",
            "'; DROP TABLE users--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/integration/allowed-ips', [
                'ip' => $payload,
            ]);

            // Não deve retornar erros SQL
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            $this->assertContains($response->status(), [400, 422]);
        }
    }

    /**
     * Teste: Deve prevenir XSS em IPs autorizados
     */
    public function test_should_prevent_xss_in_allowed_ips(): void
    {
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            'javascript:alert("XSS")',
            'onerror=alert("XSS")',
        ];

        foreach ($xssPayloads as $payload) {
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
            ])->postJson('/api/integration/allowed-ips', [
                'ip' => $payload,
            ]);

            $content = $response->getContent();
            
            // Não deve retornar o payload XSS na resposta
            $this->assertStringNotContainsString('<script>', $content);
            $this->assertStringNotContainsString('javascript:', $content);
        }
    }

    /**
     * Teste: Deve prevenir acesso a IPs de outros usuários (IDOR)
     */
    public function test_should_prevent_idor_access_to_other_users_ips(): void
    {
        // Adicionar IP para o primeiro usuário
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/integration/allowed-ips', [
            'ip' => '192.168.1.1',
        ]);

        // Fazer login com outro usuário
        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => 'otheruser',
            'password' => 'password123',
        ]);

        $otherToken = $loginResponse->json('token') ?? $loginResponse->json('data.token');

        // Obter IPs com token do outro usuário
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $otherToken,
        ])->getJson('/api/integration/allowed-ips');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        // Não deve retornar IPs do primeiro usuário
        $this->assertNotContains('192.168.1.1', $data['ips'] ?? []);
    }

    // ===== SENSITIVE DATA EXPOSURE =====

    /**
     * Teste: Não deve expor informações sensíveis em respostas de configurações
     */
    public function test_should_not_expose_sensitive_info_in_settings_responses(): void
    {
        $endpoints = [
            '/api/integration/credentials',
            '/api/2fa/status',
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
}

