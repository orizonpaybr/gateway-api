<?php

namespace App\Constants;

/**
 * Constants para configurações de afiliados
 */
class AffiliateSettings
{
    public const MIN_PERCENTAGE = 0;
    public const MAX_PERCENTAGE = 10;
    
    /**
     * Validar porcentagem
     */
    public static function isValidPercentage(float $percentage): bool
    {
        return $percentage >= self::MIN_PERCENTAGE && $percentage <= self::MAX_PERCENTAGE;
    }
}

