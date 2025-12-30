<?php

use App\Http\Requests\Api\RegisterUserRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testes Unitários - RegisterUserRequest
 * Testa: Validações, Regras de negócio
 */
uses(TestCase::class, RefreshDatabase::class);

test('validação aceita dados válidos', function () {
    $request = new RegisterUserRequest();
    
    $data = [
        'username' => 'testuser',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'telefone' => '11999999999',
        'cpf_cnpj' => '12345678900',
        'gender' => 'male',
        'password' => 'Password123!@#',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->passes())->toBeTrue();
});

test('validação requer gender obrigatório', function () {
    $request = new RegisterUserRequest();
    
    $data = [
        'username' => 'testuser',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'telefone' => '11999999999',
        'cpf_cnpj' => '12345678900',
        'password' => 'Password123!@#',
        // gender ausente
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('gender'))->toBeTrue();
});

test('validação aceita apenas male ou female para gender', function () {
    $request = new RegisterUserRequest();
    
    $invalidGenders = ['invalid', 'other', 'MALE', 'FEMALE', '', null, 123];

    foreach ($invalidGenders as $invalidGender) {
        $data = [
            'username' => 'testuser',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'telefone' => '11999999999',
            'cpf_cnpj' => '12345678900',
            'gender' => $invalidGender,
            'password' => 'Password123!@#',
        ];

        $validator = Validator::make($data, $request->rules());

        expect($validator->fails())->toBeTrue("Gender '{$invalidGender}' deveria ser inválido");
    }

    // Validar que male e female são aceitos
    foreach (['male', 'female'] as $validGender) {
        $data = [
            'username' => 'testuser',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'telefone' => '11999999999',
            'cpf_cnpj' => '12345678900',
            'gender' => $validGender,
            'password' => 'Password123!@#',
        ];

        $validator = Validator::make($data, $request->rules());

        expect($validator->passes())->toBeTrue("Gender '{$validGender}' deveria ser válido");
    }
});

test('validação requer username único', function () {
    $request = new RegisterUserRequest();
    
    // Criar usuário existente
    \App\Models\User::create([
        'username' => 'existing',
        'user_id' => 'existing',
        'name' => 'Existing User',
        'email' => 'existing@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
        'cliente_id' => \Illuminate\Support\Str::uuid()->toString(),
        'code_ref' => uniqid(),
        'status' => 1,
        'permission' => 1,
    ]);

    $data = [
        'username' => 'existing', // Duplicado
        'name' => 'Test User',
        'email' => 'test@example.com',
        'telefone' => '11999999999',
        'cpf_cnpj' => '12345678900',
        'gender' => 'male',
        'password' => 'Password123!@#',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('username'))->toBeTrue();
});

test('validação requer email único', function () {
    $request = new RegisterUserRequest();
    
    \App\Models\User::create([
        'username' => 'existinguser',
        'user_id' => 'existinguser',
        'name' => 'Existing User',
        'email' => 'existing@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password'),
        'cliente_id' => \Illuminate\Support\Str::uuid()->toString(),
        'code_ref' => uniqid(),
        'status' => 1,
        'permission' => 1,
    ]);

    $data = [
        'username' => 'testuser',
        'name' => 'Test User',
        'email' => 'existing@example.com', // Duplicado
        'telefone' => '11999999999',
        'cpf_cnpj' => '12345678900',
        'gender' => 'male',
        'password' => 'Password123!@#',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('email'))->toBeTrue();
});

test('validação valida formato de senha forte', function () {
    $request = new RegisterUserRequest();
    
    $weakPasswords = [
        'password',           // Sem maiúscula, número e especial
        'PASSWORD',           // Sem minúscula, número e especial
        'Password',           // Sem número e especial
        'Password1',          // Sem caractere especial
        'Password!',          // Sem número
        '12345678',           // Sem letras
        'Pass1!',             // Muito curta (< 8)
    ];

    foreach ($weakPasswords as $weakPassword) {
        $data = [
            'username' => 'testuser',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'telefone' => '11999999999',
            'cpf_cnpj' => '12345678900',
            'gender' => 'male',
            'password' => $weakPassword,
        ];

        $validator = Validator::make($data, $request->rules());

        expect($validator->fails())->toBeTrue("Senha '{$weakPassword}' deveria ser inválida");
    }

    // Validar senha forte
    $data = [
        'username' => 'testuser',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'telefone' => '11999999999',
        'cpf_cnpj' => '12345678900',
        'gender' => 'male',
        'password' => 'Password123!@#', // Senha forte
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->passes())->toBeTrue();
});

test('validação valida formato de documentos', function () {
    $request = new RegisterUserRequest();
    
    $data = [
        'username' => 'testuser',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'telefone' => '11999999999',
        'cpf_cnpj' => '12345678900',
        'gender' => 'male',
        'password' => 'Password123!@#',
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->passes())->toBeTrue();
});

test('validação aceita arquivos de documentos opcionais', function () {
    $request = new RegisterUserRequest();
    
    $data = [
        'username' => 'testuser',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'telefone' => '11999999999',
        'cpf_cnpj' => '12345678900',
        'gender' => 'male',
        'password' => 'Password123!@#',
        // Documentos são opcionais (não precisam estar no array)
    ];

    $validator = Validator::make($data, $request->rules());

    expect($validator->passes())->toBeTrue();
});



















