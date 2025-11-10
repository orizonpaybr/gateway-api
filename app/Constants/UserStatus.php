<?php

namespace App\Constants;

/**
 * Constants para status de usuários
 */
class UserStatus
{
    public const INACTIVE = 0;
    public const ACTIVE = 1;
    public const PENDING = 5;
    
    /**
     * Obter texto do status
     */
    public static function getText(int $status): string
    {
        return match($status) {
            self::INACTIVE => 'Inativo',
            self::ACTIVE => 'Aprovado',
            self::PENDING => 'Pendente',
            default => 'Indefinido',
        };
    }
    
    /**
     * Obter todos os status válidos
     */
    public static function getValidStatuses(): array
    {
        return [self::INACTIVE, self::ACTIVE, self::PENDING];
    }
}

