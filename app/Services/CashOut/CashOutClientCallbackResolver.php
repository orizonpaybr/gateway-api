<?php

namespace App\Services\CashOut;

use App\Models\SolicitacoesCashOut;

/**
 * Postback ao integrador externo: só quando o saque foi criado com baasPostbackUrl real (API).
 * Plataforma Coratri grava callback = "web" → sem webhook (mesma regra do estorno interno).
 */
final class CashOutClientCallbackResolver
{
    public static function resolve(SolicitacoesCashOut $cashOut): ?string
    {
        $stored = trim((string) ($cashOut->callback ?? ''));
        if ($stored === '' || $stored === 'web') {
            return null;
        }

        return $stored;
    }
}
