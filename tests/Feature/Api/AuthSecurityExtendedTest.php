<?php

use App\Models\User;
use App\Services\JwtBlacklistService;
use App\Services\JWTService;
use App\Constants\UserStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Helpers\AuthTestHelper;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

test('unlock-login limpa rate limiter da conta', function () {
    config(['auth_security.max_attempts_per_account' => 3]);

    $admin = AuthTestHelper::createTestUser([
        'username' => 'adminunlock',
        'email' => 'adminunlock@example.com',
        'permission' => 3,
        'status' => UserStatus::ACTIVE,
    ]);

    $targetData = AuthTestHelper::createTotpTestUser([
        'username' => 'ratelimituser',
        'email' => 'ratelimituser@example.com',
        'status' => UserStatus::ACTIVE,
    ]);
    $target = $targetData['user'];

    $tempToken = AuthTestHelper::generate2FATempToken($target);

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/auth/verify-2fa', [
            'temp_token' => $tempToken,
            'code' => '000000',
        ]);
    }

    $this->postJson('/api/auth/verify-2fa', [
        'temp_token' => AuthTestHelper::generate2FATempToken($target),
        'code' => '000000',
    ])->assertStatus(429);

    $token = AuthTestHelper::generateTestToken($admin);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/admin/users/'.$target->id.'/unlock-login')
        ->assertStatus(200);

    $freshToken = AuthTestHelper::generate2FATempToken($target);

    $this->postJson('/api/auth/verify-2fa', [
        'temp_token' => $freshToken,
        'code' => '000000',
    ])->assertStatus(400);
});

test('generate-qr não expõe secret TOTP na resposta', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'qruser',
        'email' => 'qruser@example.com',
        'status' => UserStatus::ACTIVE,
    ]);

    $token = AuthTestHelper::generateTestToken($user);

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/2fa/generate-qr');

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'data' => ['qr_svg']])
        ->assertJsonMissing(['secret', 'otp_url']);
});

test('generate-qr aceita token temporário de setup no login', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'qrsetup',
        'email' => 'qrsetup@example.com',
        'status' => UserStatus::ACTIVE,
        'twofa_enabled' => false,
    ]);

    $setupToken = AuthTestHelper::generate2FASetupTempToken($user);

    $this->withHeader('Authorization', 'Bearer '.$setupToken)
        ->postJson('/api/2fa/generate-qr')
        ->assertStatus(200)
        ->assertJsonStructure(['success', 'data' => ['qr_svg']]);
});

test('login de admin sem 2FA retorna requires_2fa_setup', function () {
    config(['auth_security.require_2fa_for_admins' => true]);

    AuthTestHelper::createTestUser([
        'username' => 'admin2fa',
        'email' => 'admin2fa@example.com',
        'permission' => 3,
        'status' => UserStatus::ACTIVE,
        'twofa_enabled' => false,
        'twofa_secret' => null,
        'twofa_pin' => null,
        'twofa_method' => null,
    ]);

    $this->postJson('/api/auth/login', [
        'username' => 'admin2fa',
        'password' => 'Password123!@#',
    ])
        ->assertStatus(200)
        ->assertJson([
            'success' => false,
            'requires_2fa_setup' => true,
        ])
        ->assertJsonStructure(['temp_token', 'data' => ['user']]);
});

test('login com PIN legado redireciona para setup TOTP', function () {
    AuthTestHelper::createTestUser([
        'username' => 'pinlegacy',
        'email' => 'pinlegacy@example.com',
        'status' => UserStatus::ACTIVE,
        'twofa_enabled' => true,
        'twofa_pin' => Hash::make('123456'),
        'twofa_method' => 'pin',
        'twofa_secret' => null,
    ]);

    $this->postJson('/api/auth/login', [
        'username' => 'pinlegacy',
        'password' => 'Password123!@#',
    ])
        ->assertStatus(200)
        ->assertJson([
            'success' => false,
            'requires_2fa_setup' => true,
        ])
        ->assertJsonMissing(['requires_2fa' => true]);
});

test('logout adiciona token JWT à blacklist', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'logoutuser',
        'email' => 'logoutuser@example.com',
        'status' => UserStatus::ACTIVE,
    ]);

    $token = AuthTestHelper::generateTestToken($user);
    $decoded = app(JWTService::class)->validateToken($token);
    expect($decoded)->not->toBeNull();

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/auth/logout')
        ->assertStatus(200);

    expect(app(JwtBlacklistService::class)->isBlacklisted($decoded->jti))->toBeTrue();
});

test('auth prune-events remove registros antigos', function () {
    \App\Models\AuthEvent::create([
        'event_type' => 'login_failed',
        'ip' => '127.0.0.1',
        'created_at' => now()->subDays(120),
    ]);

    \App\Models\AuthEvent::create([
        'event_type' => 'login_success',
        'ip' => '127.0.0.1',
        'created_at' => now()->subDay(),
    ]);

    $this->artisan('auth:prune-events', ['--days' => 90])
        ->assertSuccessful();

    expect(\App\Models\AuthEvent::count())->toBe(1);
});

test('register exige turnstile quando configurado', function () {
    config(['auth_security.turnstile_secret_key' => 'test-secret']);

    $data = AuthTestHelper::validRegistrationData([
        'username' => 'turnstileuser',
        'email' => 'turnstile@example.com',
    ]);

    $this->postJson('/api/auth/register', $data)
        ->assertStatus(422)
        ->assertJson([
            'success' => false,
            'requires_captcha' => true,
        ]);
});

test('register aceita turnstile token válido', function () {
    config(['auth_security.turnstile_secret_key' => 'test-secret']);

    \Illuminate\Support\Facades\Http::fake([
        'challenges.cloudflare.com/*' => \Illuminate\Support\Facades\Http::response(['success' => true]),
    ]);

    $data = AuthTestHelper::validRegistrationData([
        'username' => 'captchareg',
        'email' => 'captchareg@example.com',
        'turnstile_token' => 'valid-token',
    ]);

    $this->postJson('/api/auth/register', $data)
        ->assertStatus(201)
        ->assertJson(['success' => true]);
});

test('verify-2fa bloqueia PIN após deadline de migração TOTP', function () {
    config(['auth_security.totp_migration_deadline' => now()->subDay()->toIso8601String()]);

    $user = AuthTestHelper::createTestUser([
        'username' => 'pinmigrate',
        'email' => 'pinmigrate@example.com',
        'twofa_enabled' => true,
        'twofa_pin' => Hash::make('123456'),
        'twofa_method' => 'pin',
    ]);

    $tempToken = AuthTestHelper::generate2FATempToken($user);

    $this->postJson('/api/auth/verify-2fa', [
        'temp_token' => $tempToken,
        'code' => '123456',
    ])
        ->assertStatus(403)
        ->assertJson([
            'requires_totp_migration' => true,
            'session_terminated' => true,
        ]);
});
