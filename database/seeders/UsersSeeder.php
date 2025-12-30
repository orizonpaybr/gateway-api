<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\UsersKey;

class UsersSeeder extends Seeder
{
    /**
     * Criar usuários de teste: 2 gerentes e 2 usuários comuns
     */
    public function run(): void
    {
        $users = [
            // Gerente 1
            [
                'username' => 'gerente1',
                'name' => 'Carlos Eduardo Santos',
                'email' => 'gerente1@orizon.com',
                'password' => Hash::make('teste123'),
                'permission' => 2, // Gerente
                'status' => 1, // Ativo
                'cpf_cnpj' => '123.456.789-01',
                'telefone' => '(11) 98765-4321',
                'gender' => 'male',
                'saldo' => 5250000.50, // Acima de 1 milhão para teste
                'volume_transacional' => 12500000.00,
                'total_transacoes' => 450,
                'transacoes_aproved' => 420,
                'transacoes_recused' => 30,
                'valor_sacado' => 3200000.00,
                'valor_saque_pendente' => 150000.00,
                'cliente_id' => 'cli_gerente1_' . uniqid(),
                'user_id' => 'gerente1',
                'avatar' => '/uploads/avatars/avatar_default.jpg',
                'banido' => false,
                'saque_bloqueado' => false,
                'aprovado_alguma_vez' => true,
                'code_ref' => 'GER1' . strtoupper(substr(md5(uniqid()), 0, 6)),
                'data_nascimento' => '1985-05-15',
                'cep' => '01310-100',
                'rua' => 'Avenida Paulista',
                'numero_residencia' => '1578',
                'bairro' => 'Bela Vista',
                'cidade' => 'São Paulo',
                'estado' => 'SP',
            ],
            
            // Gerente 2
            [
                'username' => 'gerente2',
                'name' => 'Ana Paula Oliveira',
                'email' => 'gerente2@orizon.com',
                'password' => Hash::make('teste123'),
                'permission' => 2, // Gerente
                'status' => 1, // Ativo
                'cpf_cnpj' => '987.654.321-09',
                'telefone' => '(21) 97654-3210',
                'gender' => 'female',
                'saldo' => 8750000.75, // Acima de 1 milhão para teste
                'volume_transacional' => 18900000.00,
                'total_transacoes' => 680,
                'transacoes_aproved' => 650,
                'transacoes_recused' => 30,
                'valor_sacado' => 5400000.00,
                'valor_saque_pendente' => 250000.00,
                'cliente_id' => 'cli_gerente2_' . uniqid(),
                'user_id' => 'gerente2',
                'avatar' => '/uploads/avatars/avatar_default.jpg',
                'banido' => false,
                'saque_bloqueado' => false,
                'aprovado_alguma_vez' => true,
                'code_ref' => 'GER2' . strtoupper(substr(md5(uniqid()), 0, 6)),
                'data_nascimento' => '1990-08-22',
                'cep' => '20040-020',
                'rua' => 'Avenida Rio Branco',
                'numero_residencia' => '156',
                'bairro' => 'Centro',
                'cidade' => 'Rio de Janeiro',
                'estado' => 'RJ',
            ],
            
            // Usuário Comum 1
            [
                'username' => 'usuario1',
                'name' => 'João da Silva Pereira',
                'email' => 'usuario1@exemplo.com',
                'password' => Hash::make('teste123'),
                'permission' => 0, // Usuário comum
                'status' => 1, // Ativo
                'cpf_cnpj' => '456.789.123-45',
                'telefone' => '(11) 91234-5678',
                'gender' => 'male',
                'saldo' => 2150000.25, // Acima de 1 milhão para teste
                'volume_transacional' => 5600000.00,
                'total_transacoes' => 180,
                'transacoes_aproved' => 170,
                'transacoes_recused' => 10,
                'valor_sacado' => 1200000.00,
                'valor_saque_pendente' => 50000.00,
                'cliente_id' => 'cli_usuario1_' . uniqid(),
                'user_id' => 'usuario1',
                'avatar' => '/uploads/avatars/avatar_default.jpg',
                'banido' => false,
                'saque_bloqueado' => false,
                'aprovado_alguma_vez' => true,
                'code_ref' => 'USR1' . strtoupper(substr(md5(uniqid()), 0, 6)),
                'gerente_id' => 'gerente1', // Vinculado ao gerente 1
                'gerente_percentage' => 2.5,
                'data_nascimento' => '1992-03-10',
                'cep' => '04543-011',
                'rua' => 'Avenida Brigadeiro Faria Lima',
                'numero_residencia' => '3000',
                'bairro' => 'Itaim Bibi',
                'cidade' => 'São Paulo',
                'estado' => 'SP',
            ],
            
            // Usuário Comum 2
            [
                'username' => 'usuario2',
                'name' => 'Maria Fernanda Costa',
                'email' => 'usuario2@exemplo.com',
                'password' => Hash::make('teste123'),
                'permission' => 0, // Usuário comum
                'status' => 1, // Ativo
                'cpf_cnpj' => '321.654.987-32',
                'telefone' => '(21) 98765-4321',
                'gender' => 'female',
                'saldo' => 3890000.90, // Acima de 1 milhão para teste
                'volume_transacional' => 8200000.00,
                'total_transacoes' => 320,
                'transacoes_aproved' => 310,
                'transacoes_recused' => 10,
                'valor_sacado' => 2100000.00,
                'valor_saque_pendente' => 75000.00,
                'cliente_id' => 'cli_usuario2_' . uniqid(),
                'user_id' => 'usuario2',
                'avatar' => '/uploads/avatars/avatar_default.jpg',
                'banido' => false,
                'saque_bloqueado' => false,
                'aprovado_alguma_vez' => true,
                'code_ref' => 'USR2' . strtoupper(substr(md5(uniqid()), 0, 6)),
                'gerente_id' => 'gerente2', // Vinculado ao gerente 2
                'gerente_percentage' => 2.0,
                'data_nascimento' => '1988-11-25',
                'cep' => '22640-100',
                'rua' => 'Avenida Atlântica',
                'numero_residencia' => '1702',
                'bairro' => 'Copacabana',
                'cidade' => 'Rio de Janeiro',
                'estado' => 'RJ',
            ],
        ];

        // Primeiro, criar os gerentes para ter seus IDs
        $gerentes = [];
        
        foreach ($users as $userData) {
            // Se for gerente, criar primeiro e guardar o ID
            if ($userData['permission'] == 2) {
                $user = User::updateOrCreate(
                    ['email' => $userData['email']],
                    $userData
                );
                
                $gerentes[$userData['username']] = $user->id;
                
                // Criar chave de API para o usuário
                $this->createUserKeys($user->user_id);
                
                $this->command->info("Gerente criado: {$user->name} ({$user->username}) - ID: {$user->id}");
            }
        }
        
        // Depois, criar os usuários comuns vinculados aos gerentes
        foreach ($users as $userData) {
            // Se for usuário comum, vincular ao gerente pelo ID
            if ($userData['permission'] == 0) {
                // Substituir username do gerente pelo ID
                if (isset($userData['gerente_id']) && isset($gerentes[$userData['gerente_id']])) {
                    $userData['gerente_id'] = $gerentes[$userData['gerente_id']];
                } else {
                    unset($userData['gerente_id']); // Remover se não encontrar o gerente
                }
                
                $user = User::updateOrCreate(
                    ['email' => $userData['email']],
                    $userData
                );
                
                // Criar chave de API para o usuário
                $this->createUserKeys($user->user_id);
                
                $gerenteNome = isset($userData['gerente_id']) ? "vinculado ao gerente ID {$userData['gerente_id']}" : "sem gerente";
                $this->command->info("Usuário criado: {$user->name} ({$user->username}) - {$gerenteNome}");
            }
        }
    }

    /**
     * Criar chaves de API para o usuário
     */
    private function createUserKeys(string $userId): void
    {
        $userKey = UsersKey::where('user_id', $userId)->first();
        
        if (!$userKey) {
            UsersKey::create([
                'user_id' => $userId,
                'token' => \Illuminate\Support\Str::uuid()->toString(),
                'secret' => \Illuminate\Support\Str::uuid()->toString(),
                'status' => 'active',
            ]);
        }
    }
}

