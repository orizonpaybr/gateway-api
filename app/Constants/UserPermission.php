<?php

namespace App\Constants;

/**
 * Constants para permissões de usuários
 */
class UserPermission
{
    public const CLIENT = 1;
    public const MANAGER = 2;
    public const ADMIN = 3;
    
    /**
     * Obter texto da permissão
     */
    public static function getText(int $permission): string
    {
        return match($permission) {
            self::CLIENT => 'CLIENTE',
            self::MANAGER => 'GERENTE',
            self::ADMIN => 'ADMIN',
            default => 'CLIENTE',
        };
    }
    
    /**
     * Obter todas as permissões válidas
     */
    public static function getValidPermissions(): array
    {
        return [self::CLIENT, self::MANAGER, self::ADMIN];
    }
    
    /**
     * Verificar se é admin
     */
    public static function isAdmin(int $permission): bool
    {
        return $permission === self::ADMIN;
    }
}

