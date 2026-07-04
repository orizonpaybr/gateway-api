<?php

namespace App\Support;

use App\Models\User;

class AuthUserPresenter
{
    /**
     * Dados públicos do usuário retornados após login ou verify-2fa.
     *
     * @return array<string, mixed>
     */
    public static function loginProfile(User $user): array
    {
        return [
            'id' => $user->username,
            'username' => $user->username,
            'email' => $user->email ?? '',
            'name' => $user->name ?? $user->username,
            'gender' => $user->gender ?? null,
            'permission' => $user->permission ?? null,
            'status' => $user->status ?? null,
        ];
    }

    /**
     * Dados públicos retornados após cadastro (conta pendente).
     *
     * @return array<string, mixed>
     */
    public static function registrationProfile(User $user): array
    {
        return [
            'id' => $user->username,
            'username' => $user->username,
            'email' => $user->email,
            'name' => $user->name,
            'gender' => $user->gender,
            'status' => $user->status,
            'status_text' => 'Pendente de Aprovação',
        ];
    }
}
