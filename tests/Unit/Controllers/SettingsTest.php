<?php

namespace Tests\Unit\Controllers;

use Tests\TestCase;
use App\Models\UsersKey;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\TwoFactorAuthController;
use App\Http\Controllers\Api\IntegrationController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes Unitários - Configurações (Settings)
 *
 * Requer: banco de testes configurado (phpunit.xml: DB_DATABASE, DB_USERNAME, DB_PASSWORD).
 *
 * Cobre:
 * - Trocar senha (changePassword)
 * - 2FA (enable, disable, status, verify)
 * - Integração API (credentials, regenerate secret, IPs autorizados)
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    // ========== TROCAR SENHA ==========

    public function test_should_change_password_successfully()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_pwd_' . uniqid(),
            'email' => 'testuser_pwd_' . uniqid() . '@example.com',
            'password' => Hash::make('OldPassword123'),
            'twofa_enabled' => false,
        ]);

        $request = Request::create('/api/auth/change-password', 'POST', [
            'current_password' => 'OldPassword123',
            'new_password' => 'NewPassword123',
            'new_password_confirmation' => 'NewPassword123',
        ]);

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new UserController();
        $response = $controller->changePassword($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
    }

    public function test_should_require_2fa_pin_when_2fa_enabled()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser2fa_' . uniqid(),
            'email' => 'testuser2fa_' . uniqid() . '@example.com',
            'password' => Hash::make('OldPassword123'),
            'twofa_enabled' => true,
            'twofa_pin' => Hash::make('123456'),
        ]);

        $request = Request::create('/api/auth/change-password', 'POST', [
            'current_password' => 'OldPassword123',
            'new_password' => 'NewPassword123',
            'new_password_confirmation' => 'NewPassword123',
            'twofa_pin' => '123456',
        ]);

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new UserController();
        $response = $controller->changePassword($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
    }

    public function test_should_reject_invalid_current_password()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_invalid_' . uniqid(),
            'email' => 'testuser_invalid_' . uniqid() . '@example.com',
            'password' => Hash::make('OldPassword123'),
        ]);

        $request = Request::create('/api/auth/change-password', 'POST', [
            'current_password' => 'WrongPassword',
            'new_password' => 'NewPassword123',
            'new_password_confirmation' => 'NewPassword123',
        ]);

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new UserController();
        $response = $controller->changePassword($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertFalse($responseData['success']);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_should_enforce_rate_limiting()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_rate_' . uniqid(),
            'email' => 'testuser_rate_' . uniqid() . '@example.com',
            'password' => Hash::make('OldPassword123'),
        ]);

        // Simular 3 tentativas falhas
        Cache::put("change_password_attempts_{$user->id}", 3, 3600);

        $request = Request::create('/api/auth/change-password', 'POST', [
            'current_password' => 'OldPassword123',
            'new_password' => 'NewPassword123',
            'new_password_confirmation' => 'NewPassword123',
        ]);

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new UserController();
        $response = $controller->changePassword($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertFalse($responseData['success']);
        $this->assertEquals(429, $response->getStatusCode());
    }

    // ========== 2FA ==========

    public function test_should_enable_2fa()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_enable2fa_' . uniqid(),
            'email' => 'testuser_enable2fa_' . uniqid() . '@example.com',
            'twofa_enabled' => false,
        ]);

        $request = Request::create('/api/2fa/enable', 'POST', [
            'code' => '123456',
        ]);

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new TwoFactorAuthController();
        $response = $controller->enable($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertTrue($user->fresh()->twofa_enabled);
        $this->assertNotNull($user->fresh()->twofa_pin);
    }

    public function test_should_disable_2fa()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_disable2fa_' . uniqid(),
            'email' => 'testuser_disable2fa_' . uniqid() . '@example.com',
            'twofa_enabled' => true,
            'twofa_pin' => Hash::make('123456'),
        ]);

        $request = Request::create('/api/2fa/disable', 'POST', [
            'code' => '123456',
        ]);

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new TwoFactorAuthController();
        $response = $controller->disable($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertFalse($user->fresh()->twofa_enabled);
    }

    public function test_should_return_2fa_status()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_status2fa_' . uniqid(),
            'email' => 'testuser_status2fa_' . uniqid() . '@example.com',
            'twofa_enabled' => true,
            'twofa_enabled_at' => now(),
        ]);

        $request = Request::create('/api/2fa/status', 'GET');
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new TwoFactorAuthController();
        $response = $controller->status($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertTrue($responseData['enabled']);
        $this->assertTrue($responseData['configured']);
    }

    public function test_should_verify_2fa_code()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_verify2fa_' . uniqid(),
            'email' => 'testuser_verify2fa_' . uniqid() . '@example.com',
            'twofa_pin' => Hash::make('123456'),
        ]);

        $request = Request::create('/api/2fa/verify', 'POST', [
            'code' => '123456',
        ]);

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new TwoFactorAuthController();
        $response = $controller->verifyCode($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
    }

    // ========== INTEGRAÇÃO API ==========

    public function test_should_get_credentials()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_creds_' . uniqid(),
            'email' => 'testuser_creds_' . uniqid() . '@example.com',
        ]);

        // Limpar cache primeiro para garantir estado limpo
        Cache::forget("api_credentials_{$user->username}");
        
        // Criar credenciais diretamente no banco
        $userKey = UsersKey::create([
            'user_id' => $user->username,
            'token' => 'test-token',
            'secret' => 'test-secret',
            'status' => 1,
        ]);

        // Limpar cache novamente após criar para garantir que busca do banco
        Cache::forget("api_credentials_{$user->username}");

        $request = Request::create('/api/integration/credentials', 'GET');
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new IntegrationController();
        $response = $controller->getCredentials($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertNotEmpty($responseData['data']['client_key']);
        $this->assertNotEmpty($responseData['data']['client_secret']);

        // Registro deve existir no banco (token/secret podem estar criptografados)
        $this->assertDatabaseHas('users_key', [
            'user_id' => $user->username,
        ]);
    }

    public function test_should_create_credentials_if_not_exists()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_newcreds_' . uniqid(),
            'email' => 'testuser_newcreds_' . uniqid() . '@example.com',
        ]);

        $request = Request::create('/api/integration/credentials', 'GET');
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new IntegrationController();
        $response = $controller->getCredentials($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertNotEmpty($responseData['data']['client_key']);
        $this->assertNotEmpty($responseData['data']['client_secret']);
    }

    public function test_should_regenerate_secret()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_regen_' . uniqid(),
            'email' => 'testuser_regen_' . uniqid() . '@example.com',
        ]);

        // Limpar cache primeiro
        Cache::forget("api_credentials_{$user->username}");

        $userKey = UsersKey::create([
            'user_id' => $user->username,
            'token' => 'test-token',
            'secret' => 'old-secret',
            'status' => 1,
        ]);

        $oldSecret = $userKey->secret;

        $request = Request::create('/api/integration/regenerate-secret', 'POST');
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new IntegrationController();
        $response = $controller->regenerateSecret($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        
        // Buscar o userKey atualizado diretamente do banco (não usar refresh)
        $updatedUserKey = UsersKey::where('user_id', $user->username)->first();
        
        $this->assertNotNull($updatedUserKey);
        $this->assertNotEquals($oldSecret, $updatedUserKey->secret);
        $this->assertEquals($responseData['data']['client_secret'], $updatedUserKey->secret);
    }

    public function test_should_get_allowed_ips()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_ips_' . uniqid(),
            'email' => 'testuser_ips_' . uniqid() . '@example.com',
        ]);

        // Adicionar IPs usando o trait
        \App\Traits\IPManagementTrait::addAllowedIP($user, '192.168.1.1');
        \App\Traits\IPManagementTrait::addAllowedIP($user, '10.0.0.1');

        $request = Request::create('/api/integration/allowed-ips', 'GET');
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new IntegrationController();
        /** @var \Illuminate\Http\JsonResponse $response */
        $response = $controller->getAllowedIPs($request);
        /** @var array<string, mixed> $responseData */
        $responseData = json_decode($response->getContent(), true) ?? [];

        $this->assertTrue($responseData['success'] ?? false);
        $this->assertIsArray($responseData['data']['ips'] ?? null);
        $this->assertGreaterThanOrEqual(2, count($responseData['data']['ips']));
    }

    public function test_should_add_allowed_ip()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_addip_' . uniqid(),
            'email' => 'testuser_addip_' . uniqid() . '@example.com',
        ]);

        $request = Request::create('/api/integration/allowed-ips', 'POST', [
            'ip' => '192.168.1.1',
        ]);

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new IntegrationController();
        /** @var \Illuminate\Http\JsonResponse $response */
        $response = $controller->addAllowedIP($request);
        /** @var array<string, mixed> $responseData */
        $responseData = json_decode($response->getContent(), true) ?? [];

        $this->assertTrue($responseData['success'] ?? false);
        $this->assertContains('192.168.1.1', $responseData['data']['ips'] ?? []);
    }

    public function test_should_remove_allowed_ip()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_removeip_' . uniqid(),
            'email' => 'testuser_removeip_' . uniqid() . '@example.com',
        ]);

        // Adicionar IP primeiro
        \App\Traits\IPManagementTrait::addAllowedIP($user, '192.168.1.1');

        $request = Request::create('/api/integration/allowed-ips/192.168.1.1', 'DELETE');
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new IntegrationController();
        /** @var \Illuminate\Http\JsonResponse $response */
        $response = $controller->removeAllowedIP($request, '192.168.1.1');
        /** @var array<string, mixed> $responseData */
        $responseData = json_decode($response->getContent(), true) ?? [];

        $this->assertTrue($responseData['success'] ?? false);
        $this->assertNotContains('192.168.1.1', $responseData['data']['ips'] ?? []);
    }
}
