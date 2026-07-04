<?php

use App\Models\User;
use App\Models\UsersKey;
use App\Constants\UserStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Helpers\AuthTestHelper;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config([
        'auth_security.max_attempts_per_ip' => 20,
        'auth_security.lockout_tiers' => [
            ['attempts' => 3, 'minutes' => 15],
            ['attempts' => 2, 'minutes' => 30],
            ['attempts' => 1, 'minutes' => 1440],
        ],
        'auth_security.captcha_after_failures' => 99,
    ]);
});

test('login retorna mensagem genérica para credenciais inválidas', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'securuser',
        'status' => UserStatus::ACTIVE,
    ]);

    UsersKey::firstOrCreate(
        ['user_id' => $user->username],
        ['token' => 't', 'secret' => 's', 'status' => 'active'],
    );

    $wrongUser = $this->postJson('/api/auth/login', [
        'username' => 'naoexiste',
        'password' => 'wrong',
    ]);
    $wrongUser->assertStatus(401)->assertJson(['message' => 'Credenciais inválidas']);

    $wrongPass = $this->postJson('/api/auth/login', [
        'username' => 'securuser',
        'password' => 'wrongpassword',
    ]);
    $wrongPass->assertStatus(401)->assertJson(['message' => 'Credenciais inválidas']);
});

test('login bloqueia conta após 3 tentativas falhas (tier 0 → 15 min)', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'lockuser',
        'status' => UserStatus::ACTIVE,
    ]);

    for ($i = 0; $i < 2; $i++) {
        $this->postJson('/api/auth/login', [
            'username' => 'lockuser',
            'password' => 'wrong',
        ])->assertStatus(401);
    }

    $this->postJson('/api/auth/login', [
        'username' => 'lockuser',
        'password' => 'wrong',
    ])->assertStatus(429)->assertJsonStructure(['retry_after']);

    $user->refresh();
    expect($user->locked_until)->not->toBeNull();
    expect($user->login_lockout_tier)->toBe(0);

    $lockedResponse = $this->postJson('/api/auth/login', [
        'username' => 'lockuser',
        'password' => 'Password123!@#',
    ])->assertStatus(429);

    expect((int) $lockedResponse->json('retry_after'))->toBeGreaterThan(60);
});

test('login bem-sucedido limpa contador e tiers de lockout', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'clearuser',
        'status' => UserStatus::ACTIVE,
        'failed_login_attempts' => 2,
        'login_lockout_tier' => 1,
        'login_lockout_final_chance' => true,
    ]);

    UsersKey::firstOrCreate(
        ['user_id' => $user->username],
        ['token' => 't', 'secret' => 's', 'status' => 'active'],
    );

    $this->postJson('/api/auth/login', [
        'username' => 'clearuser',
        'password' => 'Password123!@#',
    ])->assertStatus(200);

    $user->refresh();
    expect($user->failed_login_attempts)->toBe(0);
    expect($user->locked_until)->toBeNull();
    expect($user->login_lockout_tier)->toBe(0);
    expect($user->login_lockout_final_chance)->toBeFalse();
});

test('rate limit por IP retorna 429', function () {
    config(['auth_security.max_attempts_per_ip' => 3]);

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/auth/login', [
            'username' => 'any',
            'password' => 'wrong',
        ])->assertStatus(401);
    }

    $this->postJson('/api/auth/login', [
        'username' => 'any',
        'password' => 'wrong',
    ])->assertStatus(429);
});

test('admin pode desbloquear login de usuário', function () {
    $admin = AuthTestHelper::createTestUser([
        'username' => 'adminlock',
        'email' => 'adminlock@example.com',
        'permission' => 3,
        'status' => UserStatus::ACTIVE,
    ]);

    $target = AuthTestHelper::createTestUser([
        'username' => 'lockedtarget',
        'email' => 'lockedtarget@example.com',
        'status' => UserStatus::ACTIVE,
    ]);
    $target->update([
        'failed_login_attempts' => 3,
        'locked_until' => now()->addMinutes(15),
        'login_lockout_tier' => 1,
        'login_lockout_final_chance' => true,
    ]);

    $token = AuthTestHelper::generateTestToken($admin);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/admin/users/'.$target->id.'/unlock-login')
        ->assertStatus(200);

    $target->refresh();
    expect($target->locked_until)->toBeNull();
    expect($target->failed_login_attempts)->toBe(0);
    expect($target->login_lockout_tier)->toBe(0);
    expect($target->login_lockout_final_chance)->toBeFalse();
});

test('lockout progride tiers: 3→15min, 2→30min, 1→24h', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'tieruser',
        'status' => UserStatus::ACTIVE,
    ]);

    // Tier 0: 3 falhas → lock 15 min
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/auth/login', ['username' => 'tieruser', 'password' => 'wrong']);
    }
    $user->refresh();
    expect($user->login_lockout_tier)->toBe(0);
    expect($user->locked_until)->not->toBeNull();

    // Simula fim do lock → avança para tier 1 (2 tentativas)
    $user->update(['locked_until' => now()->subMinute()]);
    app(\App\Services\LoginLockoutService::class)->refreshLockStateIfExpired($user->fresh());
    $user->refresh();
    expect($user->login_lockout_tier)->toBe(1);
    expect($user->failed_login_attempts)->toBe(0);

    for ($i = 0; $i < 2; $i++) {
        $this->postJson('/api/auth/login', ['username' => 'tieruser', 'password' => 'wrong']);
    }
    $user->refresh();
    expect($user->login_lockout_tier)->toBe(1);
    expect($user->locked_until)->not->toBeNull();

    // Tier 1 expira → tier 2 (1 tentativa)
    $user->update(['locked_until' => now()->subMinute()]);
    app(\App\Services\LoginLockoutService::class)->refreshLockStateIfExpired($user->fresh());
    $user->refresh();
    expect($user->login_lockout_tier)->toBe(2);

    $this->postJson('/api/auth/login', ['username' => 'tieruser', 'password' => 'wrong']);
    $user->refresh();
    expect($user->login_lockout_tier)->toBe(2);
    expect($user->locked_until)->not->toBeNull();

    // Tier 2 expira → volta ao tier 0 com final_chance
    $user->update(['locked_until' => now()->subMinute()]);
    app(\App\Services\LoginLockoutService::class)->refreshLockStateIfExpired($user->fresh());
    $user->refresh();
    expect($user->login_lockout_tier)->toBe(0);
    expect($user->login_lockout_final_chance)->toBeTrue();
});

test('terceira rodada de 3 falhas após reset de 24h bane permanentemente', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'banuser',
        'status' => UserStatus::ACTIVE,
        'login_lockout_tier' => 0,
        'login_lockout_final_chance' => true,
    ]);

    for ($i = 0; $i < 2; $i++) {
        $this->postJson('/api/auth/login', ['username' => 'banuser', 'password' => 'wrong'])
            ->assertStatus(401);
    }

    $this->postJson('/api/auth/login', ['username' => 'banuser', 'password' => 'wrong'])
        ->assertStatus(403)
        ->assertJson(['account_banned' => true]);

    $user->refresh();
    expect((bool) $user->banido)->toBeTrue();
    expect($user->locked_until)->toBeNull();
});
