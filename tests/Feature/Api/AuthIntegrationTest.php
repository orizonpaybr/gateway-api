<?php

use App\Models\User;
use App\Models\UsersKey;
use App\Constants\UserStatus;
use App\Constants\UserPermission;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Integração Completa - Login + Register + Cache + Database
 * Testa o fluxo completo de autenticação e registro
 */
uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

test('fluxo completo: registro -> login -> perfil com cache', function () {
    // 1. REGISTRO
    $registerData = AuthTestHelper::validRegistrationData([
        'username' => 'integrationuser',
        'email' => 'integration@example.com',
        'gender' => 'male',
    ]);

    $registerResponse = $this->postJson('/api/auth/register', $registerData);

    $registerResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'user' => [
                    'gender' => 'male',
                ],
            ],
        ]);

    // Verificar no banco
    $user = User::where('username', 'integrationuser')->first();
    expect($user)->not->toBeNull();
    expect($user->gender)->toBe('male');
    expect($user->status)->toBe(UserStatus::PENDING);

    // Verificar chaves de API criadas
    $userKey = UsersKey::where('user_id', $user->user_id)->first();
    expect($userKey)->not->toBeNull();

    // 2. LOGIN (após aprovar usuário)
    $user->update(['status' => UserStatus::ACTIVE]);

    $loginResponse = $this->postJson('/api/auth/login', [
        'username' => 'integrationuser',
        'password' => 'Password123!@#',
    ]);

    $loginResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'user' => [
                    'gender' => 'male',
                ],
            ],
        ]);

    $token = $loginResponse->json('data.token');
    expect($token)->not->toBeEmpty();

    // 3. PERFIL COM CACHE
    $profileResponse1 = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/user/profile');

    $profileResponse1->assertStatus(200)
        ->assertJson([
            'data' => [
                'gender' => 'male',
            ],
        ]);

    // Verificar cache foi criado
    $cacheKey = 'user_profile_integrationuser';
    expect(Cache::has($cacheKey))->toBeTrue();

    // Modificar usuário no banco
    $user->update(['gender' => 'female']);

    // Segunda requisição deve retornar cache (dados antigos)
    $profileResponse2 = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/user/profile');

    $profileResponse2->assertStatus(200)
        ->assertJson([
            'data' => [
                'gender' => 'male', // Cache antigo
            ],
        ]);

    // Limpar cache e verificar dados atualizados
    Cache::forget($cacheKey);

    $profileResponse3 = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/user/profile');

    $profileResponse3->assertStatus(200)
        ->assertJson([
            'data' => [
                'gender' => 'female', // Dados atualizados do banco
            ],
        ]);
});

test('registro com gender female -> login -> verificar gender em todas as etapas', function () {
    // Registro
    $registerData = AuthTestHelper::validRegistrationData([
        'username' => 'femaleuser',
        'email' => 'female@example.com',
        'gender' => 'female',
    ]);

    $registerResponse = $this->postJson('/api/auth/register', $registerData);
    $registerResponse->assertStatus(201);

    // Verificar no banco
    $this->assertDatabaseHas('users', [
        'username' => 'femaleuser',
        'gender' => 'female',
    ]);

    // Ativar usuário
    $user = User::where('username', 'femaleuser')->first();
    $user->update(['status' => UserStatus::ACTIVE]);

    // Login
    $loginResponse = $this->postJson('/api/auth/login', [
        'username' => 'femaleuser',
        'password' => 'Password123!@#',
    ]);

    $loginResponse->assertStatus(200)
        ->assertJson([
            'data' => [
                'user' => [
                    'gender' => 'female',
                ],
            ],
        ]);

    // Perfil
    $token = $loginResponse->json('data.token');
    $profileResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/user/profile');

    $profileResponse->assertStatus(200)
        ->assertJson([
            'data' => [
                'gender' => 'female',
            ],
        ]);
});

test('múltiplos registros com diferentes genders são salvos corretamente', function () {
    $users = [
        [
            'username' => 'male1',
            'email' => 'male1@example.com',
            'telefone' => '11911111111',
            'cpf_cnpj' => '11111111111',
            'gender' => 'male'
        ],
        [
            'username' => 'female1',
            'email' => 'female1@example.com',
            'telefone' => '11922222222',
            'cpf_cnpj' => '22222222222',
            'gender' => 'female'
        ],
        [
            'username' => 'male2',
            'email' => 'male2@example.com',
            'telefone' => '11933333333',
            'cpf_cnpj' => '33333333333',
            'gender' => 'male'
        ],
    ];

    foreach ($users as $userData) {
        $data = AuthTestHelper::validRegistrationData($userData);
        $response = $this->postJson('/api/auth/register', $data);
        $response->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'username' => $userData['username'],
            'gender' => $userData['gender'],
        ]);
    }

    // Verificar contagem
    expect(User::where('gender', 'male')->count())->toBe(2);
    expect(User::where('gender', 'female')->count())->toBe(1);
});

test('cache é invalidado quando usuário atualiza perfil', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'cacheinvalidation',
        'gender' => 'male',
    ]);

    $token = AuthTestHelper::generateTestToken($user);

    // Popular cache
    $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/user/profile');

    $cacheKey = 'user_profile_cacheinvalidation';
    expect(Cache::has($cacheKey))->toBeTrue();

    // Simular atualização de perfil (como se fosse feito via endpoint de update)
    $user->update(['gender' => 'female']);

    // Limpar cache (como o sistema faria)
    Cache::forget($cacheKey);

    // Nova requisição deve buscar dados atualizados
    $response = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/user/profile');

    $response->assertStatus(200)
        ->assertJson([
            'data' => [
                'gender' => 'female', // Dados atualizados
            ],
        ]);

    // Cache deve ser recriado com novos dados
    $newCacheData = Cache::get($cacheKey);
    expect($newCacheData['gender'])->toBe('female');
});



















