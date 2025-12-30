<?php

namespace Tests\Unit\Controllers;

use Tests\TestCase;
use App\Models\User;
use App\Http\Controllers\Api\UtmifyController;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes Unitários - Integração Utmify
 * 
 * Cobre:
 * - getConfig (obter configuração)
 * - saveConfig (salvar API Key)
 * - deleteConfig (remover API Key)
 * - testConnection (testar conexão)
 * - Validação de 2FA
 * - Cache
 */
class UtmifyTest extends TestCase
{
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

    public function test_should_get_config_when_api_key_exists()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_utmify_' . uniqid(),
            'email' => 'testuser_utmify_' . uniqid() . '@example.com',
            'integracao_utmfy' => 'test-api-key-123',
        ]);

        Cache::forget("utmify:config_{$user->username}");

        $request = Request::create('/api/utmify/config', 'GET');
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new UtmifyController();
        $response = $controller->getConfig($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertEquals('test-api-key-123', $responseData['data']['api_key']);
        $this->assertTrue($responseData['data']['enabled']);
    }

    public function test_should_get_config_when_api_key_not_exists()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_noapi_' . uniqid(),
            'email' => 'testuser_noapi_' . uniqid() . '@example.com',
            'integracao_utmfy' => null,
        ]);

        Cache::forget("utmify:config_{$user->username}");

        $request = Request::create('/api/utmify/config', 'GET');
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new UtmifyController();
        $response = $controller->getConfig($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertNull($responseData['data']['api_key']);
        $this->assertFalse($responseData['data']['enabled']);
    }

    public function test_should_return_401_without_authentication()
    {
        $request = Request::create('/api/utmify/config', 'GET');
        $request->setUserResolver(function () {
            return null;
        });

        $controller = new UtmifyController();
        $response = $controller->getConfig($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertFalse($responseData['success']);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_should_save_config_without_2fa()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_save_' . uniqid(),
            'email' => 'testuser_save_' . uniqid() . '@example.com',
            'twofa_enabled' => false,
            'integracao_utmfy' => null,
        ]);

        Cache::forget("utmify:config_{$user->username}");

        $request = Request::create('/api/utmify/config', 'POST', [
            'api_key' => 'new-api-key-456',
        ]);

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new UtmifyController();
        $response = $controller->saveConfig($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertEquals('new-api-key-456', $user->fresh()->integracao_utmfy);
        $this->assertTrue($responseData['data']['enabled']);
    }

    public function test_should_save_config_with_2fa()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_2fa_' . uniqid(),
            'email' => 'testuser_2fa_' . uniqid() . '@example.com',
            'twofa_enabled' => true,
            'twofa_pin' => Hash::make('123456'),
            'integracao_utmfy' => null,
        ]);

        Cache::forget("utmify:config_{$user->username}");

        $request = Request::create('/api/utmify/config', 'POST', [
            'api_key' => 'new-api-key-789',
            'pin' => '123456',
        ]);

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new UtmifyController();
        $response = $controller->saveConfig($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertEquals('new-api-key-789', $user->fresh()->integracao_utmfy);
    }

    public function test_should_require_2fa_pin_when_2fa_enabled()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_require2fa_' . uniqid(),
            'email' => 'testuser_require2fa_' . uniqid() . '@example.com',
            'twofa_enabled' => true,
            'twofa_pin' => Hash::make('123456'),
        ]);

        $request = Request::create('/api/utmify/config', 'POST', [
            'api_key' => 'new-api-key',
        ]);

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new UtmifyController();
        $response = $controller->saveConfig($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertFalse($responseData['success']);
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertTrue($responseData['requires_2fa'] ?? false);
    }

    public function test_should_reject_invalid_2fa_pin()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_invalidpin_' . uniqid(),
            'email' => 'testuser_invalidpin_' . uniqid() . '@example.com',
            'twofa_enabled' => true,
            'twofa_pin' => Hash::make('123456'),
        ]);

        $request = Request::create('/api/utmify/config', 'POST', [
            'api_key' => 'new-api-key',
            'pin' => '000000',
        ]);

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new UtmifyController();
        $response = $controller->saveConfig($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertFalse($responseData['success']);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function test_should_validate_api_key_required()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_validate_' . uniqid(),
            'email' => 'testuser_validate_' . uniqid() . '@example.com',
        ]);

        $request = Request::create('/api/utmify/config', 'POST', []);

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new UtmifyController();
        $response = $controller->saveConfig($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertFalse($responseData['success']);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function test_should_delete_config_without_2fa()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_delete_' . uniqid(),
            'email' => 'testuser_delete_' . uniqid() . '@example.com',
            'twofa_enabled' => false,
            'integracao_utmfy' => 'existing-api-key',
        ]);

        Cache::forget("utmify:config_{$user->username}");

        $request = Request::create('/api/utmify/config', 'DELETE');

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new UtmifyController();
        $response = $controller->deleteConfig($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertNull($user->fresh()->integracao_utmfy);
        $this->assertFalse($responseData['data']['enabled']);
    }

    public function test_should_delete_config_with_2fa()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_delete2fa_' . uniqid(),
            'email' => 'testuser_delete2fa_' . uniqid() . '@example.com',
            'twofa_enabled' => true,
            'twofa_pin' => Hash::make('123456'),
            'integracao_utmfy' => 'existing-api-key',
        ]);

        Cache::forget("utmify:config_{$user->username}");

        $request = Request::create('/api/utmify/config', 'DELETE', [
            'pin' => '123456',
        ]);

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new UtmifyController();
        $response = $controller->deleteConfig($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertNull($user->fresh()->integracao_utmfy);
    }

    public function test_should_test_connection_when_api_key_exists()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_test_' . uniqid(),
            'email' => 'testuser_test_' . uniqid() . '@example.com',
            'integracao_utmfy' => 'test-api-key',
        ]);

        $request = Request::create('/api/utmify/test', 'POST');

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new UtmifyController();
        $response = $controller->testConnection($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);
        $this->assertEquals('connected', $responseData['data']['status']);
    }

    public function test_should_fail_test_connection_when_api_key_not_exists()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_notest_' . uniqid(),
            'email' => 'testuser_notest_' . uniqid() . '@example.com',
            'integracao_utmfy' => null,
        ]);

        $request = Request::create('/api/utmify/test', 'POST');

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new UtmifyController();
        $response = $controller->testConnection($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertFalse($responseData['success']);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function test_should_use_cache_for_config()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_cache_' . uniqid(),
            'email' => 'testuser_cache_' . uniqid() . '@example.com',
            'integracao_utmfy' => 'cached-api-key',
        ]);

        Cache::forget("utmify:config_{$user->username}");

        $request = Request::create('/api/utmify/config', 'GET');
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new UtmifyController();
        
        // Primeira chamada - deve buscar do banco
        $response1 = $controller->getConfig($request);
        $responseData1 = json_decode($response1->getContent(), true);
        $this->assertTrue($responseData1['success']);

        // Atualizar no banco diretamente
        $user->integracao_utmfy = 'updated-api-key';
        $user->save();

        // Segunda chamada - deve usar cache (ainda tem valor antigo)
        $response2 = $controller->getConfig($request);
        $responseData2 = json_decode($response2->getContent(), true);
        $this->assertEquals('cached-api-key', $responseData2['data']['api_key']);

        // Limpar cache e buscar novamente
        Cache::forget("utmify:config_{$user->username}");
        $response3 = $controller->getConfig($request);
        $responseData3 = json_decode($response3->getContent(), true);
        $this->assertEquals('updated-api-key', $responseData3['data']['api_key']);
    }

    public function test_should_clear_cache_on_save()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_clearcache_' . uniqid(),
            'email' => 'testuser_clearcache_' . uniqid() . '@example.com',
            'integracao_utmfy' => 'old-api-key',
        ]);

        // Criar cache primeiro
        Cache::put("utmify:config_{$user->username}", [
            'api_key' => 'old-api-key',
            'enabled' => true,
        ], 300);

        $request = Request::create('/api/utmify/config', 'POST', [
            'api_key' => 'new-api-key',
        ]);

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new UtmifyController();
        $response = $controller->saveConfig($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);

        // Cache deve ter sido limpo
        $cached = Cache::get("utmify:config_{$user->username}");
        $this->assertNull($cached);
    }

    public function test_should_clear_cache_on_delete()
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'testuser_clearcache2_' . uniqid(),
            'email' => 'testuser_clearcache2_' . uniqid() . '@example.com',
            'integracao_utmfy' => 'existing-api-key',
        ]);

        // Criar cache primeiro
        Cache::put("utmify:config_{$user->username}", [
            'api_key' => 'existing-api-key',
            'enabled' => true,
        ], 300);

        $request = Request::create('/api/utmify/config', 'DELETE');

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        $controller = new UtmifyController();
        $response = $controller->deleteConfig($request);
        $responseData = json_decode($response->getContent(), true);

        $this->assertTrue($responseData['success']);

        // Cache deve ter sido limpo
        $cached = Cache::get("utmify:config_{$user->username}");
        $this->assertNull($cached);
    }
}









