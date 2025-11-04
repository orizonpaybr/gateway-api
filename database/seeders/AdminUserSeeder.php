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
        $email = env('ADMIN_EMAIL', 'admin@admin.com');
        $username = env('ADMIN_USERNAME', 'admin');
        $password = env('ADMIN_PASSWORD', 'admin123');

        User::updateOrCreate(
            [
                'email' => $email,
            ],
            [
                'username' => $username,
                'name' => 'Administrador',
                'password' => Hash::make($password),
                'permission' => 3, // 3 = admin
                'status' => 1,     // aprovado/ativo
                'saldo' => 0,
            ]
        );
    }
}
