<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use App\Models\User;
use App\Models\UsersKey;
use App\Models\NotificationPreference;
use App\Constants\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Integração - API de Configurações
 * 
 * Cobre:
 * - Trocar senha
 * - 2FA (enable, disable, status, verify)
 * - Integração API (credentials, regenerate secret, IPs autorizados)
 * - Preferências de notificação
 */
class SettingsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Criar usuário e obter token
        $this->user = AuthTestHelper::createTestUser([
            'username' => 'testuser_' . uniqid(),
            'email' => 'testuser_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'status' => 1,
            'banido' => 0,
        ]);

        $this->token = AuthTestHelper::generateTestToken($this->user);
    }

    // ========== TROCAR SENHA ==========

    public function test_should_change_password_with_authentication()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/auth/change-password', [
            'current_password' => 'password123',
            'new_password' => 'NewPassword123',
            'new_password_confirmation' => 'NewPassword123',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertTrue(Hash::check('NewPassword123', $this->user->fresh()->password));
    }

    public function test_should_return_401_without_authentication()
    {
        $response = $this->postJson('/api/auth/change-password', [
            'current_password' => 'password123',
            'new_password' => 'NewPassword123',
            'new_password_confirmation' => 'NewPassword123',
        ]);

        $response->assertStatus(401);
    }

    public function test_should_require_2fa_pin_when_2fa_enabled()
    {
        $this->user->update([
            'twofa_enabled' => true,
            'twofa_pin' => Hash::make('123456'),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/auth/change-password', [
            'current_password' => 'password123',
            'new_password' => 'NewPassword123',
            'new_password_confirmation' => 'NewPassword123',
            'twofa_pin' => '123456',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ========== 2FA ==========

    public function test_should_enable_2fa()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/2fa/enable', [
            'code' => '123456',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertTrue($this->user->fresh()->twofa_enabled);
    }

    public function test_should_disable_2fa()
    {
        $this->user->update([
            'twofa_enabled' => true,
            'twofa_pin' => Hash::make('123456'),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/2fa/disable', [
            'code' => '123456',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertFalse($this->user->fresh()->twofa_enabled);
    }

    public function test_should_return_2fa_status()
    {
        $this->user->update([
            'twofa_enabled' => true,
            'twofa_enabled_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/2fa/status');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'enabled' => true,
                'configured' => true,
            ]);
    }

    public function test_should_verify_2fa_code()
    {
        $this->user->update([
            'twofa_pin' => Hash::make('123456'),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/2fa/verify', [
            'code' => '123456',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ========== INTEGRAÇÃO API ==========

    public function test_should_get_credentials()
    {
        UsersKey::create([
            'user_id' => $this->user->username,
            'token' => 'test-token',
            'secret' => 'test-secret',
            'status' => 1,
        ]);

        Cache::forget("api_credentials_{$this->user->username}");

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/integration/credentials');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'client_key',
                    'client_secret',
                    'status',
                    'created_at',
                ],
            ]);
    }

    public function test_should_regenerate_secret()
    {
        // Limpar cache primeiro
        Cache::forget("api_credentials_{$this->user->username}");

        $userKey = UsersKey::create([
            'user_id' => $this->user->username,
            'token' => 'test-token',
            'secret' => 'old-secret',
            'status' => 1,
        ]);

        $oldSecret = $userKey->secret;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/integration/regenerate-secret');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Buscar o userKey atualizado diretamente do banco
        $updatedUserKey = UsersKey::where('user_id', $this->user->username)->first();
        
        $this->assertNotNull($updatedUserKey);
        $this->assertNotEquals($oldSecret, $updatedUserKey->secret);
        $this->assertEquals($response->json('data.client_secret'), $updatedUserKey->secret);
    }

    public function test_should_get_allowed_ips()
    {
        \App\Traits\IPManagementTrait::addAllowedIP($this->user, '192.168.1.1');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/integration/allowed-ips');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'ips',
                    'count',
                ],
            ]);
    }

    public function test_should_add_allowed_ip()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/integration/allowed-ips', [
            'ip' => '192.168.1.1',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $ips = \App\Traits\IPManagementTrait::getAllowedIPs($this->user->fresh());
        $this->assertContains('192.168.1.1', $ips);
    }

    public function test_should_remove_allowed_ip()
    {
        \App\Traits\IPManagementTrait::addAllowedIP($this->user, '192.168.1.1');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->deleteJson('/api/integration/allowed-ips/192.168.1.1');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $ips = \App\Traits\IPManagementTrait::getAllowedIPs($this->user->fresh());
        $this->assertNotContains('192.168.1.1', $ips);
    }

    // ========== PREFERÊNCIAS DE NOTIFICAÇÃO ==========

    public function test_should_get_notification_preferences()
    {
        NotificationPreference::create([
            'user_id' => $this->user->username,
            'push_enabled' => true,
            'notify_transactions' => true,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/notification-preferences');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'push_enabled',
                    'notify_transactions',
                    'notify_deposits',
                    'notify_withdrawals',
                    'notify_security',
                    'notify_system',
                ],
            ]);
    }

    public function test_should_update_notification_preferences()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->putJson('/api/notification-preferences', [
            'push_enabled' => false,
            'notify_transactions' => false,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $preferences = NotificationPreference::where('user_id', $this->user->username)->first();
        $this->assertFalse($preferences->push_enabled);
        $this->assertFalse($preferences->notify_transactions);
    }

    public function test_should_toggle_notification_preference()
    {
        NotificationPreference::create([
            'user_id' => $this->user->username,
            'notify_transactions' => true,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/notification-preferences/toggle/notify_transactions');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $preferences = NotificationPreference::where('user_id', $this->user->username)->first();
        $this->assertFalse($preferences->notify_transactions);
    }
}

