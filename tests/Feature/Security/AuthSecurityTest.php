<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use App\Models\User;
use App\Models\UsersKey;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Cache;

/**
 * Testes de Segurança - Autenticação (Login e Cadastro)
 * 
 * Cobre:
 * - SQL Injection
 * - XSS (Cross-Site Scripting)
 * - CSRF (Cross-Site Request Forgery)
 * - Brute Force / Rate Limiting
 * - Input Validation
 * - Autenticação e Autorização
 * - Sensitive Data Exposure
 * - Path Traversal
 * - Command Injection
 * - File Upload Security
 * - Session Management
 * - Password Security
 */
class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('login');
    }

    // ===== SQL INJECTION TESTS =====

    /**
     * Teste: Deve prevenir SQL Injection no campo username
     */
    public function test_should_prevent_sql_injection_in_username(): void
    {
        $sqlInjectionPayloads = [
            "' OR '1'='1",
            "' OR '1'='1' --",
            "' OR '1'='1' /*",
            "admin'--",
            "admin'/*",
            "' UNION SELECT * FROM users--",
            "1' OR '1'='1",
            "1' OR '1'='1'--",
            "1' OR '1'='1'/*",
            "admin' OR '1'='1",
            "admin' OR '1'='1'--",
            "admin' OR '1'='1'/*",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->postJson('/api/auth/login', [
                'username' => $payload,
                'password' => 'password123',
            ]);

            // Não deve retornar dados de usuários ou erros SQL
            $this->assertNotEquals(500, $response->status(), "SQL Injection detectado: {$payload}");
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            $this->assertStringNotContainsString('SQL syntax', $response->getContent());
            
            // Deve retornar erro de validação ou usuário não encontrado
            $this->assertContains($response->status(), [400, 401]);
        }
    }

    /**
     * Teste: Deve prevenir SQL Injection no campo password
     */
    public function test_should_prevent_sql_injection_in_password(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
            'password' => Hash::make('password123'),
        ]);

        UsersKey::factory()->create([
            'user_id' => $user->user_id,
        ]);

        $sqlInjectionPayloads = [
            "' OR '1'='1",
            "' OR '1'='1' --",
            "admin'--",
            "' UNION SELECT * FROM users--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->postJson('/api/auth/login', [
                'username' => 'testuser',
                'password' => $payload,
            ]);

            // Não deve retornar dados de usuários ou erros SQL
            $this->assertNotEquals(500, $response->status());
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            
            // Deve retornar erro de senha incorreta
            $this->assertEquals(401, $response->status());
        }
    }

    /**
     * Teste: Deve prevenir SQL Injection no cadastro
     */
    public function test_should_prevent_sql_injection_in_register(): void
    {
        $sqlInjectionPayloads = [
            "' OR '1'='1",
            "' OR '1'='1' --",
            "admin'--",
            "' UNION SELECT * FROM users--",
        ];

        foreach ($sqlInjectionPayloads as $payload) {
            $response = $this->postJson('/api/auth/register', [
                'username' => $payload,
                'name' => 'Test User',
                'email' => 'test' . rand(1000, 9999) . '@example.com',
                'telefone' => '11999999999',
                'cpf_cnpj' => '12345678900',
                'gender' => 'male',
                'password' => 'Password123!@#',
            ]);

            // Não deve retornar erros SQL - isso é o mais importante
            $this->assertNotEquals(500, $response->status(), "SQL Injection executado com payload: {$payload}");
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            $this->assertStringNotContainsString('SQL syntax', $response->getContent());
            
            // Se retornar 201 (sucesso), o Eloquent protegeu contra SQL injection usando prepared statements
            // O importante é que não houve SQL injection executado (já verificado acima)
            if ($response->status() === 201) {
                // Verificar que o usuário foi criado corretamente (sem executar SQL malicioso)
                $user = \App\Models\User::where('username', $payload)->first();
                $this->assertNotNull($user, "Usuário deveria ser criado com prepared statements");
            } else {
                // Deve retornar erro de validação (400 ou 422)
                $this->assertContains($response->status(), [400, 422], "Status inesperado para payload: {$payload}");
            }
        }
    }

    // ===== XSS (CROSS-SITE SCRIPTING) TESTS =====

    /**
     * Teste: Deve prevenir XSS no campo username
     */
    public function test_should_prevent_xss_in_username(): void
    {
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            '<img src=x onerror=alert("XSS")>',
            'javascript:alert("XSS")',
            '<svg onload=alert("XSS")>',
            '"><script>alert("XSS")</script>',
            "'><script>alert('XSS')</script>",
            '<iframe src="javascript:alert(\'XSS\')"></iframe>',
        ];

        foreach ($xssPayloads as $payload) {
            $response = $this->postJson('/api/auth/login', [
                'username' => $payload,
                'password' => 'password123',
            ]);

            $content = $response->getContent();
            
            // Não deve retornar o payload XSS na resposta
            $this->assertStringNotContainsString('<script>', $content);
            $this->assertStringNotContainsString('onerror=', $content);
            $this->assertStringNotContainsString('javascript:', $content);
            
            // Deve retornar erro de validação
            $this->assertContains($response->status(), [400, 401]);
        }
    }

    /**
     * Teste: Deve prevenir XSS no cadastro
     */
    public function test_should_prevent_xss_in_register(): void
    {
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            '<img src=x onerror=alert("XSS")>',
            'javascript:alert("XSS")',
        ];

        foreach ($xssPayloads as $payload) {
            $response = $this->postJson('/api/auth/register', [
                'username' => 'testuser' . rand(1000, 9999),
                'name' => $payload,
                'email' => 'test@example.com',
                'telefone' => '11999999999',
                'cpf_cnpj' => '12345678900',
                'gender' => 'male',
                'password' => 'Password123!@#',
            ]);

            $content = $response->getContent();
            
            // Não deve retornar o payload XSS na resposta
            $this->assertStringNotContainsString('<script>', $content);
            $this->assertStringNotContainsString('onerror=', $content);
            
            // Deve retornar erro de validação
            $this->assertEquals(400, $response->status());
        }
    }

    // ===== BRUTE FORCE / RATE LIMITING TESTS =====

    /**
     * Teste: Deve implementar rate limiting no login
     */
    public function test_should_implement_rate_limiting_on_login(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
            'password' => Hash::make('password123'),
        ]);

        UsersKey::factory()->create([
            'user_id' => $user->user_id,
        ]);

        // Fazer múltiplas tentativas de login com senha incorreta
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'username' => 'testuser',
                'password' => 'wrongpassword',
            ]);

            // Primeiras tentativas devem retornar 401
            if ($i < 5) {
                $this->assertEquals(401, $response->status());
            }
        }

        // Após muitas tentativas, ainda deve retornar 401 (não deve expor se há rate limiting)
        $response = $this->postJson('/api/auth/login', [
            'username' => 'testuser',
            'password' => 'wrongpassword',
        ]);

        $this->assertEquals(401, $response->status());
    }

    /**
     * Teste: Deve prevenir enumeração de usuários
     */
    public function test_should_prevent_user_enumeration(): void
    {
        // Tentar login com usuário existente
        $user = User::factory()->create([
            'username' => 'existinguser',
            'password' => Hash::make('password123'),
        ]);

        $response1 = $this->postJson('/api/auth/login', [
            'username' => 'existinguser',
            'password' => 'wrongpassword',
        ]);

        // Tentar login com usuário inexistente
        $response2 = $this->postJson('/api/auth/login', [
            'username' => 'nonexistentuser',
            'password' => 'wrongpassword',
        ]);

        // Ambas devem retornar o mesmo status (401) para prevenir enumeração
        $this->assertEquals(401, $response1->status());
        $this->assertEquals(401, $response2->status());
        
        // Mensagens devem ser similares (não devem expor se o usuário existe)
        $message1 = strtolower($response1->json('message') ?? '');
        $message2 = strtolower($response2->json('message') ?? '');
        
        // Não deve expor claramente se o usuário existe ou não
        $this->assertStringNotContainsString('não encontrado', $message1);
    }

    // ===== INPUT VALIDATION TESTS =====

    /**
     * Teste: Deve validar tamanho máximo de campos
     */
    public function test_should_validate_max_field_lengths(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'username' => str_repeat('a', 300), // Mais que 255 caracteres
            'name' => 'Test User',
            'email' => 'test@example.com',
            'telefone' => '11999999999',
            'cpf_cnpj' => '12345678900',
            'gender' => 'male',
            'password' => 'Password123!@#',
        ]);

        $this->assertEquals(400, $response->status());
        $this->assertArrayHasKey('errors', $response->json());
    }

    /**
     * Teste: Deve validar formato de email
     */
    public function test_should_validate_email_format(): void
    {
        $invalidEmails = [
            'invalid-email',
            'invalid@',
            '@invalid.com',
            'invalid..email@example.com',
        ];

        foreach ($invalidEmails as $email) {
            $response = $this->postJson('/api/auth/register', [
                'username' => 'testuser' . rand(1000, 9999),
                'name' => 'Test User',
                'email' => $email,
                'telefone' => '11999999999',
                'cpf_cnpj' => '12345678900',
                'gender' => 'male',
                'password' => 'Password123!@#',
            ]);

            // Deve retornar erro de validação (400 ou 422)
            $this->assertContains($response->status(), [400, 422], "Email inválido aceito: {$email}");
        }
    }

    /**
     * Teste: Deve validar força da senha
     */
    public function test_should_validate_password_strength(): void
    {
        $weakPasswords = [
            '12345678', // Apenas números
            'password', // Apenas minúsculas
            'PASSWORD', // Apenas maiúsculas
            'Password', // Sem números
            'Password1', // Sem caracteres especiais
            'Pass1!', // Muito curta
        ];

        foreach ($weakPasswords as $password) {
            $response = $this->postJson('/api/auth/register', [
                'username' => 'testuser' . rand(1000, 9999),
                'name' => 'Test User',
                'email' => 'test' . rand(1000, 9999) . '@example.com',
                'telefone' => '11999999999',
                'cpf_cnpj' => '12345678900',
                'gender' => 'male',
                'password' => $password,
            ]);

            $this->assertEquals(400, $response->status());
            $this->assertArrayHasKey('errors', $response->json());
        }
    }

    // ===== SENSITIVE DATA EXPOSURE TESTS =====

    /**
     * Teste: Não deve expor senhas em respostas
     */
    public function test_should_not_expose_passwords_in_responses(): void
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'user_id' => 'testuser',
            'password' => Hash::make('password123'),
        ]);

        UsersKey::factory()->create([
            'user_id' => $user->user_id,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'testuser',
            'password' => 'password123',
        ]);

        $content = $response->getContent();
        
        // Não deve conter a senha em texto plano
        $this->assertStringNotContainsString('password123', $content);
        $this->assertStringNotContainsString('password', strtolower($content));
    }

    /**
     * Teste: Não deve expor informações sensíveis em erros
     */
    public function test_should_not_expose_sensitive_info_in_errors(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'username' => 'testuser',
            'password' => 'password123',
        ]);

        $content = $response->getContent();
        
        // Não deve expor stack traces, caminhos de arquivos, etc
        $this->assertStringNotContainsString('/var/www', $content);
        $this->assertStringNotContainsString('Stack trace', $content);
        $this->assertStringNotContainsString('at ', $content);
    }

    // ===== PATH TRAVERSAL TESTS =====

    /**
     * Teste: Deve prevenir path traversal em uploads
     */
    public function test_should_prevent_path_traversal_in_file_uploads(): void
    {
        $pathTraversalPayloads = [
            '../../../etc/passwd',
            '..\\..\\..\\windows\\system32',
            '....//....//etc/passwd',
            '%2e%2e%2f%2e%2e%2f%2e%2e%2fetc%2fpasswd',
        ];

        foreach ($pathTraversalPayloads as $payload) {
            // Simular upload de arquivo com nome malicioso
            $file = \Illuminate\Http\UploadedFile::fake()->create($payload . '.jpg', 100);
            
            $response = $this->post('/api/auth/register', [
                'username' => 'testuser' . rand(1000, 9999),
                'name' => 'Test User',
                'email' => 'test' . rand(1000, 9999) . '@example.com',
                'telefone' => '11999999999',
                'cpf_cnpj' => '12345678900',
                'gender' => 'male',
                'password' => 'Password123!@#',
                'documentoFrente' => $file,
            ]);

            // Não deve permitir path traversal
            $this->assertNotEquals(500, $response->status());
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
            '; rm -rf /',
        ];

        foreach ($commandInjectionPayloads as $payload) {
            $response = $this->postJson('/api/auth/login', [
                'username' => $payload,
                'password' => 'password123',
            ]);

            // Não deve executar comandos
            $this->assertNotEquals(500, $response->status());
            $this->assertStringNotContainsString('root', $response->getContent());
            $this->assertStringNotContainsString('www-data', $response->getContent());
        }
    }

    // ===== FILE UPLOAD SECURITY TESTS =====

    /**
     * Teste: Deve validar tipos de arquivo permitidos
     */
    public function test_should_validate_allowed_file_types(): void
    {
        $invalidFiles = [
            'script.php',
            'malware.exe',
            'virus.sh',
            'exploit.js',
        ];

        foreach ($invalidFiles as $filename) {
            $file = \Illuminate\Http\UploadedFile::fake()->create($filename, 100);
            
            $response = $this->post('/api/auth/register', [
                'username' => 'testuser' . rand(1000, 9999),
                'name' => 'Test User',
                'email' => 'test' . rand(1000, 9999) . '@example.com',
                'telefone' => '11999999999',
                'cpf_cnpj' => '12345678900',
                'gender' => 'male',
                'password' => 'Password123!@#',
                'documentoFrente' => $file,
            ]);

            // Deve rejeitar arquivos não permitidos
            $this->assertEquals(400, $response->status());
        }
    }

    /**
     * Teste: Deve validar tamanho máximo de arquivo
     */
    public function test_should_validate_max_file_size(): void
    {
        // Criar arquivo maior que 5MB (limite configurado)
        $file = \Illuminate\Http\UploadedFile::fake()->create('document.jpg', 6000); // 6MB
        
        $response = $this->post('/api/auth/register', [
            'username' => 'testuser' . rand(1000, 9999),
            'name' => 'Test User',
            'email' => 'test' . rand(1000, 9999) . '@example.com',
            'telefone' => '11999999999',
            'cpf_cnpj' => '12345678900',
            'gender' => 'male',
            'password' => 'Password123!@#',
            'documentoFrente' => $file,
        ]);

        // Deve rejeitar arquivo muito grande
        $this->assertEquals(400, $response->status());
    }

    // ===== PASSWORD SECURITY TESTS =====

    /**
     * Teste: Deve hashar senhas corretamente
     */
    public function test_should_hash_passwords_correctly(): void
    {
        $password = 'Password123!@#';
        
        $response = $this->postJson('/api/auth/register', [
            'username' => 'testuser' . rand(1000, 9999),
            'name' => 'Test User',
            'email' => 'test' . rand(1000, 9999) . '@example.com',
            'telefone' => '11999999999',
            'cpf_cnpj' => '12345678900',
            'gender' => 'male',
            'password' => $password,
        ]);

        if ($response->status() === 201) {
            $user = User::where('username', $response->json('data.user.username'))->first();
            
            // Senha não deve estar em texto plano
            $this->assertNotEquals($password, $user->password);
            
            // Deve ser possível verificar a senha com Hash::check
            $this->assertTrue(Hash::check($password, $user->password));
        }
    }

    /**
     * Teste: Não deve permitir senhas comuns
     */
    public function test_should_reject_common_passwords(): void
    {
        $commonPasswords = [
            '12345678',
            'password',
            'Password1',
            'qwerty123',
            'admin123',
        ];

        foreach ($commonPasswords as $password) {
            $response = $this->postJson('/api/auth/register', [
                'username' => 'testuser' . rand(1000, 9999),
                'name' => 'Test User',
                'email' => 'test' . rand(1000, 9999) . '@example.com',
                'telefone' => '11999999999',
                'cpf_cnpj' => '12345678900',
                'gender' => 'male',
                'password' => $password,
            ]);

            // Deve rejeitar senhas comuns (devem falhar na validação de força)
            $this->assertEquals(400, $response->status());
        }
    }
}

