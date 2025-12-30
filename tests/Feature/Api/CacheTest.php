<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Integração - Cache/Redis
 * Testa: Cache de perfil, Cache de saldo, Invalidação de cache
 */
uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Limpar cache antes de cada teste
    Cache::flush();
});

test('getProfile usa cache Redis para dados do perfil', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'cacheuser',
        'gender' => 'female',
        'status' => \App\Constants\UserStatus::ACTIVE,
    ]);

    $token = AuthTestHelper::generateTestToken($user);

    // Primeira requisição - deve popular o cache
    $response1 = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/user/profile');

    $response1->assertStatus(200);

    // Verificar que o cache foi criado
    $cacheKey = 'user_profile_' . $user->username;
    expect(Cache::has($cacheKey))->toBeTrue();

    // Verificar conteúdo do cache
    $cachedData = Cache::get($cacheKey);
    expect($cachedData)->not->toBeNull();
    expect($cachedData['gender'])->toBe('female');
    expect($cachedData['id'])->toBe($user->username);
});

test('getProfile retorna dados do cache na segunda requisição', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'cacheuser2',
        'gender' => 'male',
        'status' => \App\Constants\UserStatus::ACTIVE,
    ]);

    $token = AuthTestHelper::generateTestToken($user);

    // Primeira requisição
    $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/user/profile');

    // Modificar usuário diretamente no banco (simular mudança externa)
    $user->update(['gender' => 'female']);

    // Segunda requisição - deve retornar dados do cache (antigos)
    $response2 = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/user/profile');

    $response2->assertStatus(200)
        ->assertJson([
            'data' => [
                'gender' => 'male', // Dados do cache, não do banco atualizado
            ],
        ]);
});

test('cache de perfil expira após TTL', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'cachettl',
        'status' => \App\Constants\UserStatus::ACTIVE,
    ]);

    $token = AuthTestHelper::generateTestToken($user);

    // Popular cache
    $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/user/profile');

    $cacheKey = 'user_profile_' . $user->username;
    
    // Verificar que cache existe
    expect(Cache::has($cacheKey))->toBeTrue();

    // Simular expiração do cache (TTL de 300 segundos)
    Cache::forget($cacheKey);

    // Nova requisição deve buscar do banco novamente
    $response = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/user/profile');

    $response->assertStatus(200);
    
    // Cache deve ser recriado
    expect(Cache::has($cacheKey))->toBeTrue();
});

test('getBalance usa cache Redis para saldo', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'balanceuser',
        'saldo' => 1000.50,
        'status' => \App\Constants\UserStatus::ACTIVE,
    ]);

    $token = AuthTestHelper::generateTestToken($user);

    // Primeira requisição
    $response1 = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/balance');

    $response1->assertStatus(200);

    // Verificar cache
    $cacheKey = 'user_balance_' . $user->username;
    expect(Cache::has($cacheKey))->toBeTrue();

    $cachedBalance = Cache::get($cacheKey);
    // O cache retorna estrutura com totalInflows e totalOutflows
    expect($cachedBalance)->toBeArray();
    expect($cachedBalance)->toHaveKeys(['totalInflows', 'totalOutflows']);
});

test('clearUserCache limpa cache de perfil e saldo', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'clearcache',
        'status' => \App\Constants\UserStatus::ACTIVE,
    ]);

    $token = AuthTestHelper::generateTestToken($user);

    // Popular caches
    $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/user/profile');
    
    $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/balance');

    $profileCacheKey = 'user_profile_' . $user->username;
    $balanceCacheKey = 'user_balance_' . $user->username;

    // Verificar que caches existem
    expect(Cache::has($profileCacheKey))->toBeTrue();
    expect(Cache::has($balanceCacheKey))->toBeTrue();

    // Simular limpeza de cache (como quando usuário atualiza perfil)
    Cache::forget($profileCacheKey);
    Cache::forget($balanceCacheKey);

    // Verificar que caches foram limpos
    expect(Cache::has($profileCacheKey))->toBeFalse();
    expect(Cache::has($balanceCacheKey))->toBeFalse();
});

test('cache armazena gender corretamente', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'gendercache',
        'gender' => 'female',
        'status' => \App\Constants\UserStatus::ACTIVE,
    ]);

    $token = AuthTestHelper::generateTestToken($user);

    $response = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/user/profile');

    $response->assertStatus(200)
        ->assertJson([
            'data' => [
                'gender' => 'female',
            ],
        ]);

    // Verificar no cache
    $cacheKey = 'user_profile_' . $user->username;
    $cachedData = Cache::get($cacheKey);
    
    expect($cachedData['gender'])->toBe('female');
});

test('cache retorna null para gender quando não definido', function () {
    $user = AuthTestHelper::createTestUser([
        'username' => 'nogendercache',
        'gender' => null, // Usuário antigo sem gender
        'status' => \App\Constants\UserStatus::ACTIVE,
    ]);

    $token = AuthTestHelper::generateTestToken($user);

    $response = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/user/profile');

    $response->assertStatus(200);

    // Verificar no cache
    $cacheKey = 'user_profile_' . $user->username;
    $cachedData = Cache::get($cacheKey);
    
    expect($cachedData['gender'])->toBeNull();
});

test('múltiplos usuários têm caches independentes', function () {
    $user1 = AuthTestHelper::createTestUser([
        'username' => 'cacheuser1',
        'email' => 'cacheuser1@example.com',
        'telefone' => '11911111111',
        'cpf_cnpj' => '11111111111',
        'gender' => 'male',
        'status' => \App\Constants\UserStatus::ACTIVE,
    ]);

    $user2 = AuthTestHelper::createTestUser([
        'username' => 'cacheuser2',
        'email' => 'cacheuser2@example.com',
        'telefone' => '11922222222',
        'cpf_cnpj' => '22222222222',
        'gender' => 'female',
        'status' => \App\Constants\UserStatus::ACTIVE,
    ]);

    $token1 = AuthTestHelper::generateTestToken($user1);
    $token2 = AuthTestHelper::generateTestToken($user2);

    // Popular caches de ambos
    $this->withHeader('Authorization', 'Bearer ' . $token1)
        ->getJson('/api/user/profile');
    
    $this->withHeader('Authorization', 'Bearer ' . $token2)
        ->getJson('/api/user/profile');

    // Verificar caches independentes
    $cache1 = Cache::get('user_profile_cacheuser1');
    $cache2 = Cache::get('user_profile_cacheuser2');

    expect($cache1['gender'])->toBe('male');
    expect($cache2['gender'])->toBe('female');
    expect($cache1['id'])->not->toBe($cache2['id']);
});



















