<?php

use App\Models\User;
use App\Constants\UserStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Helpers\AuthTestHelper;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config([
        'auth_security.max_attempts_per_ip' => 20,
        'auth_security.max_2fa_attempts_per_temp_token' => 5,
        'auth_security.lockout_tiers' => [
            ['attempts' => 3, 'minutes' => 15],
            ['attempts' => 2, 'minutes' => 30],
            ['attempts' => 1, 'minutes' => 1440],
        ],
        'auth_security.2fa_captcha_after_failures' => 3,
    ]);
});

test('verify-2fa retorna 400 para código inválido', function () {
    ['user' => $user] = AuthTestHelper::createTotpTestUser([
        'username' => 'twofauser',
        'email' => 'twofa@example.com',
    ]);

    $tempToken = AuthTestHelper::generate2FATempToken($user);

    $response = $this->postJson('/api/auth/verify-2fa', [
        'temp_token' => $tempToken,
        'code' => '000000',
    ]);

    $response->assertStatus(400)
        ->assertJson([
            'success' => false,
            'message' => 'Código inválido',
        ]);
});

test('verify-2fa encerra sessão após muitas tentativas inválidas', function () {
    ['user' => $user] = AuthTestHelper::createTotpTestUser([
        'username' => 'twofalock',
        'email' => 'twofalock@example.com',
    ]);

    $tempToken = AuthTestHelper::generate2FATempToken($user);

    for ($i = 0; $i < 2; $i++) {
        $this->postJson('/api/auth/verify-2fa', [
            'temp_token' => $tempToken,
            'code' => '000000',
        ])->assertStatus(400);
    }

    $this->postJson('/api/auth/verify-2fa', [
        'temp_token' => $tempToken,
        'code' => '000000',
    ])
        ->assertStatus(429)
        ->assertJson([
            'success' => false,
            'session_terminated' => true,
        ]);
});

test('verify-2fa bloqueia conta temporariamente após falhas consecutivas', function () {
    ['user' => $user] = AuthTestHelper::createTotpTestUser([
        'username' => 'twofacct',
        'email' => 'twofacct@example.com',
    ]);

    $tempToken = AuthTestHelper::generate2FATempToken($user);

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/auth/verify-2fa', [
            'temp_token' => $tempToken,
            'code' => '000000',
        ]);
    }

    $user->refresh();
    expect($user->locked_until)->not->toBeNull();
});

test('verify-2fa exige captcha após várias falhas por IP', function () {
    config([
        'auth_security.max_2fa_attempts_per_temp_token' => 20,
        'auth_security.lockout_tiers' => [
            ['attempts' => 50, 'minutes' => 15],
            ['attempts' => 50, 'minutes' => 30],
            ['attempts' => 50, 'minutes' => 1440],
        ],
    ]);

    ['user' => $user] = AuthTestHelper::createTotpTestUser([
        'username' => 'twofacap',
        'email' => 'twofacap@example.com',
    ]);

    $tempToken = AuthTestHelper::generate2FATempToken($user);

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/auth/verify-2fa', [
            'temp_token' => $tempToken,
            'code' => '000000',
        ]);
    }

    $response = $this->postJson('/api/auth/verify-2fa', [
        'temp_token' => $tempToken,
        'code' => '000000',
    ]);

    $response->assertStatus(400)
        ->assertJson([
            'requires_captcha' => true,
        ]);
});

test('verify-2fa aceita token de setup após configurar 2FA', function () {
    ['user' => $user, 'code' => $code] = AuthTestHelper::createTotpTestUser([
        'username' => 'twofasetup',
        'email' => 'twofasetup@example.com',
    ]);

    $tempToken = AuthTestHelper::generate2FASetupTempToken($user);

    $this->postJson('/api/auth/verify-2fa', [
        'temp_token' => $tempToken,
        'code' => $code,
    ])
        ->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure(['data' => ['token', 'user']]);
});

test('verify-2fa bem-sucedido com código correto', function () {
    ['user' => $user, 'code' => $code] = AuthTestHelper::createTotpTestUser([
        'username' => 'twofaok',
        'email' => 'twofaok@example.com',
    ]);

    $tempToken = AuthTestHelper::generate2FATempToken($user);

    $response = $this->postJson('/api/auth/verify-2fa', [
        'temp_token' => $tempToken,
        'code' => $code,
    ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    $user->refresh();
    expect($user->failed_login_attempts)->toBe(0);
    expect($user->locked_until)->toBeNull();
});

test('middleware bloqueia verify-2fa quando conta está em lockout', function () {
    ['user' => $user] = AuthTestHelper::createTotpTestUser([
        'username' => 'twofarate',
        'email' => 'twofarate@example.com',
    ]);

    $tempToken = AuthTestHelper::generate2FATempToken($user);

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/auth/verify-2fa', [
            'temp_token' => $tempToken,
            'code' => '000000',
        ]);
    }

    $freshToken = AuthTestHelper::generate2FATempToken($user);

    $this->postJson('/api/auth/verify-2fa', [
        'temp_token' => $freshToken,
        'code' => '000000',
    ])->assertStatus(429);
});
