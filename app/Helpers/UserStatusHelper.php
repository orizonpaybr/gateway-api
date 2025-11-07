<?php

namespace App\Helpers;

use App\Models\User;
use App\Constants\UserStatus;

/**
 * Helper para lógica de status de usuário
 * Centraliza regras de negócio relacionadas a status
 * Segue princípio DRY (Don't Repeat Yourself)
 */
class UserStatusHelper
{
    /**
     * Determinar texto de status do usuário
     * 
     * Regras:
     * - "Bloqueado" se banido = true
     * - "Inativo" se status = INACTIVE e banido = false (excluído)
     * - Caso contrário, usar texto padrão do status
     * 
     * @param User $user
     * @return string
     */
    public static function getStatusText(User $user): string
    {
        // Se está banido, sempre mostrar "Bloqueado"
        if ($user->banido) {
            return 'Bloqueado';
        }
        
        // Se está inativo e não banido, é um usuário excluído
        if ($user->status == UserStatus::INACTIVE && !$user->banido) {
            return 'Inativo';
        }
        
        // Caso contrário, usar texto padrão do status
        return UserStatus::getText($user->status);
    }
    
    /**
     * Verificar se usuário pode fazer login
     * 
     * @param User $user
     * @return bool
     */
    public static function canLogin(User $user): bool
    {
        // Não pode fazer login se:
        // - Status é INACTIVE (excluído)
        // - banido = true (bloqueado)
        return $user->status != UserStatus::INACTIVE && !$user->banido;
    }
    
    /**
     * Verificar se usuário está bloqueado
     * 
     * @param User $user
     * @return bool
     */
    public static function isBlocked(User $user): bool
    {
        return (bool) ($user->banido ?? false);
    }
    
    /**
     * Verificar se usuário está excluído (inativo)
     * 
     * @param User $user
     * @return bool
     */
    public static function isDeleted(User $user): bool
    {
        return $user->status == UserStatus::INACTIVE && !$user->banido;
    }
}

