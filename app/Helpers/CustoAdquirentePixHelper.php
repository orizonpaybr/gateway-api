<?php

namespace App\Helpers;

/**
 * Custo fixo da rede/adquirente por transação PIX (split interno: taxa cliente − custo − afiliado).
 */
class CustoAdquirentePixHelper
{
    /**
     * Custo fixo por transação conforme a referência do adquirente (executor_ordem / resolve Pix).
     *
     * @see config('simpay.custo_fixo_transacao')
     * @see config('fyhub.custo_fixo_transacao')
     * @see config('treeal.custo_fixo_transacao')
     */
    public static function custoFixoTransacao(?string $adquirenteReferencia = null): float
    {
        return match ($adquirenteReferencia) {
            'fyhub' => (float) config('fyhub.custo_fixo_transacao', 0.04),
            'simpay' => (float) config('simpay.custo_fixo_transacao', 0.035),
            'treeal' => (float) config('treeal.custo_fixo_transacao', 0.03),
            default => 0.0,
        };
    }

    /**
     * Referência da adquirente PRINCIPAL, usada como base do piso de taxa.
     *
     * Hoje a principal é a Treeal. Configurável via .env (ADQUIRENTE_PRINCIPAL).
     */
    public static function adquirentePrincipal(): string
    {
        return (string) env('ADQUIRENTE_PRINCIPAL', 'treeal');
    }

    /**
     * Piso da taxa (em R$) que a taxa percentual nunca pode subverter.
     *
     * É a taxa padrão em centavos da adquirente principal (custo fixo por
     * transação). Garante que o valor cobrado via percentual nunca seja menor
     * do que o custo cobrado pela adquirente.
     */
    public static function pisoCentavos(): float
    {
        return self::custoFixoTransacao(self::adquirentePrincipal());
    }
}
