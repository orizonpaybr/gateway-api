<?php

namespace App\Helpers;

/**
 * Custo da rede/adquirente por transação PIX (split interno: taxa cliente − custo − afiliado).
 *
 * Treeal: percentual sobre o valor (ex.: 1%).
 * Fyhub / Simpay: custo fixo em R$ por transação.
 */
class CustoAdquirentePixHelper
{
    /**
     * Percentual cobrado pela adquirente sobre o valor da transação (ex.: 1 = 1%).
     */
    public static function percentualTransacao(?string $adquirenteReferencia = null): float
    {
        return match ($adquirenteReferencia) {
            'treeal' => (float) config('treeal.taxa_percentual_transacao', 1.0),
            default => 0.0,
        };
    }

    /**
     * Custo fixo por transação (R$). Treeal não usa mais custo fixo — retorna 0.
     *
     * @see config('simpay.custo_fixo_transacao')
     * @see config('fyhub.custo_fixo_transacao')
     */
    public static function custoFixoTransacao(?string $adquirenteReferencia = null): float
    {
        return match ($adquirenteReferencia) {
            'fyhub' => (float) config('fyhub.custo_fixo_transacao', 0.04),
            'simpay' => (float) config('simpay.custo_fixo_transacao', 0.035),
            'treeal' => 0.0,
            default => 0.0,
        };
    }

    /**
     * Custo total da adquirente para uma transação (R$).
     *
     * Percentual (Treeal) tem prioridade; demais adquirentes usam custo fixo.
     */
    public static function custoTransacao(float $amount, ?string $adquirenteReferencia = null): float
    {
        $amount = max(0, $amount);
        $percentual = self::percentualTransacao($adquirenteReferencia);

        if ($percentual > 0 && $amount > 0) {
            return ($amount * $percentual) / 100;
        }

        return self::custoFixoTransacao($adquirenteReferencia);
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
     * Percentual mínimo da adquirente principal (ex.: 1% Treeal).
     *
     * Usado como piso percentual ao configurar taxas individuais por porcentagem.
     */
    public static function percentualPrincipal(): float
    {
        return self::percentualTransacao(self::adquirentePrincipal());
    }

    /**
     * Piso da taxa cobrada do usuário (em R$) no modo percentual.
     *
     * Garante que o valor cobrado nunca seja menor que o custo da adquirente principal.
     */
    public static function pisoTaxa(float $amount): float
    {
        return self::custoTransacao($amount, self::adquirentePrincipal());
    }

    /**
     * Expressão SQL para custo da adquirente por linha (usa executor_ordem e amount).
     */
    public static function sqlCustoPorTransacaoExpr(string $amountColumn = 'amount'): string
    {
        $custoSimpay = (float) config('simpay.custo_fixo_transacao', 0.035);
        $custoFyhub = (float) config('fyhub.custo_fixo_transacao', 0.04);
        $pctTreeal = (float) config('treeal.taxa_percentual_transacao', 1.0);

        return "(CASE
            WHEN executor_ordem = 'treeal' THEN ({$amountColumn} * {$pctTreeal} / 100)
            WHEN executor_ordem = 'fyhub' THEN {$custoFyhub}
            WHEN executor_ordem = 'simpay' OR executor_ordem = 'Adquirente PIX' OR adquirente_ref = 'Adquirente PIX' THEN {$custoSimpay}
            ELSE {$custoSimpay}
        END)";
    }
}
