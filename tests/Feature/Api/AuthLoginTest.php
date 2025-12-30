<?php

use App\Models\User;
use App\Models\UsersKey;
use App\Constants\UserStatus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Integração - Login API
 * Testa: Endpoint, Banco de Dados, Cache, Validações
 */
uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Limpar cache antes de cada teste
    Cache::flush();
});

test('login com credenciais válidas retorna token e dados do usuário', function () {
    // Arrange: Criar usuário de teste
    $user = AuthTestHelper::createTestUser([
        'username' => 'testuser',
        'email' => 'test@example.com',
        'password' => Hash::make('Password123!@#'),
        'status' => UserStatus::ACTIVE,
        'banido' => false, // Garantir que não está banido
    ]);

    // Act: Fazer login
    $response = $this->postJson('/api/auth/login', [
        'username' => 'testuser',
        'password' => 'Password123!@#',
    ], [
        'Accept' => 'application/json',
    ]);

    // Assert: Verificar resposta
    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'user' => [
                    'id',
                    'username',
                    'email',
                    'name',
                    'gender',
                    'permission',
                    'status',
                ],
                'token',
                'api_token',
                'api_secret',
            ],
        ])
        ->assertJson([
            'success' => true,
            'data' => [
                'user' => [
                    'username' => 'testuser',
                    'email' => 'test@example.com',
                ],
            ],
        ]);

    // Verificar que o token foi retornado
    expect($response->json('data.token'))->not->toBeEmpty();
    expect($response->json('data.api_token'))->not->toBeEmpty();
});

test('login com email ao invés de username funciona', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'testuser',
        'email' => 'test@example.com',
        'password' => Hash::make('Password123!@#'),
        'status' => UserStatus::ACTIVE,
        'banido' => false,
    ]);

    $response = $this->postJson('/api/auth/login', [
        'username' => 'test@example.com', // Usando email
        'password' => 'Password123!@#',
    ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);
});

test('login com senha incorreta retorna erro', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'testuser',
        'password' => Hash::make('Password123!@#'),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'username' => 'testuser',
        'password' => 'WrongPassword123!@#',
    ]);

    $response->assertStatus(401)
        ->assertJson([
            'success' => false,
        ]);
});

test('login com usuário inexistente retorna erro', function () {
    $response = $this->postJson('/api/auth/login', [
        'username' => 'nonexistent',
        'password' => 'Password123!@#',
    ]);

    $response->assertStatus(401)
        ->assertJson([
            'success' => false,
        ]);
});

test('login requer validação de campos obrigatórios', function () {
    $response = $this->postJson('/api/auth/login', []);

    $response->assertStatus(400)
        ->assertJsonValidationErrors(['username', 'password']);
});

test('login com usuário inativo retorna erro', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'inactiveuser',
        'status' => UserStatus::INACTIVE,
        'password' => Hash::make('Password123!@#'),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'username' => 'inactiveuser',
        'password' => 'Password123!@#',
    ]);

    $response->assertStatus(403)
        ->assertJson([
            'success' => false,
        ]);
});

test('login com 2FA ativado retorna requires_2fa', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'user2fa',
        'twofa_enabled' => true,
        'twofa_pin' => Hash::make('123456'),
        'password' => Hash::make('Password123!@#'),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'username' => 'user2fa',
        'password' => 'Password123!@#',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => false,
            'requires_2fa' => true,
        ])
        ->assertJsonStructure([
            'temp_token',
        ]);
});

test('login salva gender corretamente no banco de dados', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'gendertest',
        'gender' => 'female',
        'password' => Hash::make('Password123!@#'),
        'status' => UserStatus::ACTIVE,
        'banido' => false,
    ]);

    $response = $this->postJson('/api/auth/login', [
        'username' => 'gendertest',
        'password' => 'Password123!@#',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'data' => [
                'user' => [
                    'gender' => 'female',
                ],
            ],
        ]);

    // Verificar no banco
    $this->assertDatabaseHas('users', [
        'username' => 'gendertest',
        'gender' => 'female',
    ]);
});

test('login cria chaves de API no banco de dados', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'apikeytest',
        'password' => Hash::make('Password123!@#'),
    ]);

    // Verificar que as chaves foram criadas
    $this->assertDatabaseHas('users_key', [
        'user_id' => $user->user_id,
        'status' => 'active',
    ]);

    $userKey = UsersKey::where('user_id', $user->user_id)->first();
    expect($userKey)->not->toBeNull();
    expect($userKey->token)->not->toBeEmpty();
    expect($userKey->secret)->not->toBeEmpty();
});



















