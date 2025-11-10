<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Cria ou atualiza um usuário administrador padrão
        // Importante: o frontend exibe o menu de Administração quando permission === 3
        
        // Configurações para ambiente local (gateway_orizon)
        $adminEmail = env('ADMIN_EMAIL', 'admin@exemplo.com');
        $adminUsername = env('ADMIN_USERNAME', 'admin');
        $adminPassword = env('ADMIN_PASSWORD', 'teste123');
        
        $testEmail = env('TEST_EMAIL', 'teste@exemplo.com');
        $testUsername = env('TEST_USERNAME', 'usuario_teste');
        $testPassword = env('TEST_PASSWORD', 'teste123');

        // Criar/Atualizar usuário Admin
        $admin = User::updateOrCreate(
            [
                'email' => $adminEmail,
            ],
            [
                'username' => $adminUsername,
                'name' => 'Usuário Teste Admin',
                'password' => Hash::make($adminPassword),
                'permission' => 3, // 3 = admin (obrigatório para ver menu admin)
                'status' => 1,     // aprovado/ativo
                'saldo' => 0,
                'cpf_cnpj' => '000.000.000-00',
                'telefone' => '(00) 0000-0000',
                'cliente_id' => 'cliente_admin',
                'user_id' => $adminUsername, // Necessário para users_key
            ]
        );

        // Criar/Atualizar usuário de teste normal
        $testUser = User::updateOrCreate(
            [
                'email' => $testEmail,
            ],
            [
                'username' => $testUsername,
                'name' => 'Usuário Teste',
                'password' => Hash::make($testPassword),
                'permission' => 0, // 0 = usuário normal
                'status' => 1,     // aprovado/ativo
                'saldo' => 0,
                'cpf_cnpj' => '111.111.111-11',
                'telefone' => '(11) 1111-1111',
                'cliente_id' => 'cliente_teste',
                'user_id' => $testUsername, // Necessário para users_key
            ]
        );

        // Criar chaves de API para os usuários
        $this->createUserKeys($adminUsername);
        $this->createUserKeys($testUsername);
    }

    /**
     * Criar chaves de API para o usuário
     */
    private function createUserKeys(string $userId): void
    {
        $userKey = \App\Models\UsersKey::where('user_id', $userId)->first();
        
        if (!$userKey) {
            \App\Models\UsersKey::create([
                'user_id' => $userId,
                'token' => \Illuminate\Support\Str::uuid()->toString(),
                'secret' => \Illuminate\Support\Str::uuid()->toString(),
                'status' => 'active',
            ]);
        }
    }
}
