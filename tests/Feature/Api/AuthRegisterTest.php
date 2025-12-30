<?php

use App\Models\User;
use App\Models\UsersKey;
use App\Constants\UserPermission;
use App\Constants\UserStatus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes de Integração - Register API
 * Testa: Endpoint, Banco de Dados, Validações, Upload de arquivos, Gender
 */
uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Limpar cache e storage antes de cada teste
    Cache::flush();
    Storage::fake('public');
});

test('registro com dados válidos cria usuário no banco de dados', function () {
    $data = AuthTestHelper::validRegistrationData([
        'username' => 'newuser',
        'email' => 'newuser@example.com',
        'gender' => 'male',
    ]);

    $response = $this->postJson('/api/auth/register', $data);

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'pending_approval' => true,
            ],
        ]);

    // Verificar no banco de dados
    $this->assertDatabaseHas('users', [
        'username' => 'newuser',
        'email' => 'newuser@example.com',
        'gender' => 'male',
        'status' => UserStatus::PENDING,
        'permission' => UserPermission::CLIENT,
    ]);

    // Verificar que as chaves de API foram criadas
    $user = User::where('username', 'newuser')->first();
    $this->assertDatabaseHas('users_key', [
        'user_id' => $user->user_id,
        'status' => 'active',
    ]);
});

test('registro com gender female salva corretamente', function () {
    $data = AuthTestHelper::validRegistrationData([
        'username' => 'femaleuser',
        'gender' => 'female',
    ]);

    $response = $this->postJson('/api/auth/register', $data);

    $response->assertStatus(201);

    $this->assertDatabaseHas('users', [
        'username' => 'femaleuser',
        'gender' => 'female',
    ]);

    // Verificar que gender está no retorno
    $response->assertJson([
        'data' => [
            'user' => [
                'gender' => 'female',
            ],
        ],
    ]);
});

test('registro requer campo gender obrigatório', function () {
    $data = AuthTestHelper::validRegistrationData();
    unset($data['gender']);

    $response = $this->postJson('/api/auth/register', $data);

    $response->assertStatus(400)
        ->assertJsonValidationErrors(['gender']);
});

test('registro valida gender apenas aceita male ou female', function () {
    $data = AuthTestHelper::validRegistrationData([
        'gender' => 'invalid',
    ]);

    $response = $this->postJson('/api/auth/register', $data);

    $response->assertStatus(400)
        ->assertJsonValidationErrors(['gender']);
});

test('registro valida username único', function () {
    // Criar usuário existente
    AuthTestHelper::createTestUser([
        'username' => 'existinguser',
    ]);

    $data = AuthTestHelper::validRegistrationData([
        'username' => 'existinguser', // Duplicado
    ]);

    $response = $this->postJson('/api/auth/register', $data);

    $response->assertStatus(400)
        ->assertJsonValidationErrors(['username']);
});

test('registro valida email único', function () {
    AuthTestHelper::createTestUser([
        'email' => 'existing@example.com',
    ]);

    $data = AuthTestHelper::validRegistrationData([
        'email' => 'existing@example.com', // Duplicado
    ]);

    $response = $this->postJson('/api/auth/register', $data);

    $response->assertStatus(400)
        ->assertJsonValidationErrors(['email']);
});

test('registro valida telefone único', function () {
    AuthTestHelper::createTestUser([
        'telefone' => '11999999999',
    ]);

    $data = AuthTestHelper::validRegistrationData([
        'telefone' => '11999999999', // Duplicado
    ]);

    $response = $this->postJson('/api/auth/register', $data);

    $response->assertStatus(400)
        ->assertJsonValidationErrors(['telefone']);
});

test('registro valida cpf_cnpj único', function () {
    AuthTestHelper::createTestUser([
        'cpf_cnpj' => '12345678900',
    ]);

    $data = AuthTestHelper::validRegistrationData([
        'cpf_cnpj' => '12345678900', // Duplicado
    ]);

    $response = $this->postJson('/api/auth/register', $data);

    $response->assertStatus(400)
        ->assertJsonValidationErrors(['cpf_cnpj']);
});

test('registro valida senha forte', function () {
    $data = AuthTestHelper::validRegistrationData([
        'password' => 'weak', // Senha fraca
    ]);

    $response = $this->postJson('/api/auth/register', $data);

    $response->assertStatus(400)
        ->assertJsonValidationErrors(['password']);
});

test('registro aceita upload de documentos', function () {
    $data = AuthTestHelper::validRegistrationData();

    $documentoFrente = UploadedFile::fake()->image('documento.jpg', 800, 600);
    $documentoVerso = UploadedFile::fake()->image('documento2.jpg', 800, 600);
    $selfie = UploadedFile::fake()->image('selfie.jpg', 400, 400);

    $response = $this->post('/api/auth/register', array_merge($data, [
        'documentoFrente' => $documentoFrente,
        'documentoVerso' => $documentoVerso,
        'selfieDocumento' => $selfie,
    ]));

    $response->assertStatus(201);

    // Verificar que os arquivos foram salvos
    $user = User::where('username', $data['username'])->first();
    expect($user->foto_rg_frente)->not->toBeNull();
    expect($user->foto_rg_verso)->not->toBeNull();
    expect($user->selfie_rg)->not->toBeNull();

    // Verificar que os arquivos existem no storage
    Storage::disk('public')->assertExists(
        str_replace('/storage/', '', $user->foto_rg_frente)
    );
});

test('registro cria usuário com status pendente', function () {
    $data = AuthTestHelper::validRegistrationData();

    $response = $this->postJson('/api/auth/register', $data);

    $response->assertStatus(201);

    $this->assertDatabaseHas('users', [
        'username' => $data['username'],
        'status' => UserStatus::PENDING,
    ]);
});

test('registro cria usuário com permission CLIENT', function () {
    $data = AuthTestHelper::validRegistrationData();

    $response = $this->postJson('/api/auth/register', $data);

    $this->assertDatabaseHas('users', [
        'username' => $data['username'],
        'permission' => UserPermission::CLIENT,
    ]);
});

test('registro gera code_ref único', function () {
    $data1 = AuthTestHelper::validRegistrationData([
        'username' => 'user1',
        'email' => 'user1@example.com',
        'telefone' => '11911111111',
        'cpf_cnpj' => '11111111111',
    ]);
    $data2 = AuthTestHelper::validRegistrationData([
        'username' => 'user2',
        'email' => 'user2@example.com',
        'telefone' => '11922222222',
        'cpf_cnpj' => '22222222222',
    ]);

    $response1 = $this->postJson('/api/auth/register', $data1);
    $response2 = $this->postJson('/api/auth/register', $data2);

    $response1->assertStatus(201);
    $response2->assertStatus(201);

    $user1 = User::where('username', 'user1')->first();
    $user2 = User::where('username', 'user2')->first();

    expect($user1)->not->toBeNull();
    expect($user2)->not->toBeNull();
    expect($user1->code_ref)->not->toBe($user2->code_ref);
    expect($user1->code_ref)->not->toBeEmpty();
    expect($user2->code_ref)->not->toBeEmpty();
});

test('registro com ref code atribui gerente corretamente', function () {
    $gerente = AuthTestHelper::createTestManager([
        'code_ref' => 'REF123',
        'gerente_percentage' => 5.00,
    ]);

    $data = AuthTestHelper::validRegistrationData([
        'ref' => 'REF123',
    ]);

    $response = $this->postJson('/api/auth/register', $data);

    $user = User::where('username', $data['username'])->first();

    expect($user->gerente_id)->toBe($gerente->id);
    expect((float) $user->gerente_percentage)->toBe(5.0);
});

test('registro cria saldo inicial como zero', function () {
    $data = AuthTestHelper::validRegistrationData();

    $response = $this->postJson('/api/auth/register', $data);

    $user = User::where('username', $data['username'])->first();

    expect($user->saldo)->toBe(0.0);
});

test('registro cria avatar padrão', function () {
    $data = AuthTestHelper::validRegistrationData();

    $response = $this->postJson('/api/auth/register', $data);

    $user = User::where('username', $data['username'])->first();

    expect($user->avatar)->toBe('/uploads/avatars/avatar_default.jpg');
});



















