<?php

namespace Tests\Feature\Helpers;

use App\Models\User;
use App\Models\UsersKey;
use App\Constants\UserPermission;
use App\Constants\UserStatus;
use Illuminate\Support\Facades\Hash;

/**
 * Helper para criar dados de teste de autenticação
 * DRY: Evita duplicação de código nos testes
 */
class AuthTestHelper
{
    /**
     * Cria um usuário de teste com todas as dependências
     */
    public static function createTestUser(array $attributes = []): User
    {
        $defaults = [
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => Hash::make('Password123!@#'),
            'gender' => 'male',
            'telefone' => '11999999999',
            'cpf_cnpj' => '12345678900',
            'status' => UserStatus::ACTIVE,
            'permission' => UserPermission::CLIENT,
            'banido' => false,
            'code_ref' => uniqid(),
            'avatar' => '/uploads/avatars/avatar_default.jpg',
        ];

        // Garantir que user_id e cliente_id sejam definidos baseado no username
        $merged = array_merge($defaults, $attributes);
        if (!isset($merged['user_id'])) {
            $merged['user_id'] = $merged['username'];
        }
        if (!isset($merged['cliente_id'])) {
            $merged['cliente_id'] = \Illuminate\Support\Str::uuid()->toString();
        }

        $user = User::create($merged);

        // Criar chaves de API
        UsersKey::create([
            'user_id' => $user->user_id ?? $user->username,
            'token' => \Illuminate\Support\Str::uuid()->toString(),
            'secret' => \Illuminate\Support\Str::uuid()->toString(),
            'status' => 'active',
        ]);

        return $user->fresh();
    }

    /**
     * Cria um gerente de teste
     */
    public static function createTestManager(array $attributes = []): User
    {
        return self::createTestUser(array_merge([
            'permission' => UserPermission::MANAGER,
            'gerente_percentage' => 5.00,
        ], $attributes));
    }

    /**
     * Cria dados de registro válidos
     */
    public static function validRegistrationData(array $overrides = []): array
    {
        return array_merge([
            'username' => 'newuser',
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'gender' => 'female',
            'telefone' => '11988888888',
            'cpf_cnpj' => '98765432100',
            'password' => 'Password123!@#',
        ], $overrides);
    }

    /**
     * Gera token JWT simples para testes
     */
    public static function generateTestToken(User $user): string
    {
        $userKeys = UsersKey::where('user_id', $user->user_id ?? $user->username)->first();
        
        if (!$userKeys) {
            // Criar chaves se não existirem
            $userKeys = UsersKey::create([
                'user_id' => $user->user_id ?? $user->username,
                'token' => \Illuminate\Support\Str::uuid()->toString(),
                'secret' => \Illuminate\Support\Str::uuid()->toString(),
                'status' => 'active',
            ]);
        }
        
        return base64_encode(json_encode([
            'user_id' => $user->username,
            'token' => $userKeys->token,
            'secret' => $userKeys->secret,
            'expires_at' => now()->addHours(24)->timestamp
        ]));
    }

    /**
     * Alias para generateTestToken - compatibilidade com testes existentes
     */
    public static function getAuthToken(User $user): string
    {
        return self::generateTestToken($user);
    }
}



















