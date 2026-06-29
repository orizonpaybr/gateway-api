<?php

namespace App\Helpers;

/**
 * Custo da rede/adquirente por transação PIX (split interno: taxa cliente − custo − afiliado).
 *
 * Treeal / Fyhub / Simpay: custo fixo em R$ por transação.
 */
class CustoAdquirentePixHelper
{
    /**
     * Percentual cobrado pela adquirente sobre o valor da transação (ex.: 1 = 1%).
     */
    public static function percentualTransacao(?string $adquirenteReferencia = null): float
    {
        return 0.0;
    }

    /**
     * Custo fixo por transação (R$).
     *
     * @see config('treeal.custo_fixo_transacao')
     * @see config('simpay.custo_fixo_transacao')
     * @see config('fyhub.custo_fixo_transacao')
     */
    public static function custoFixoTransacao(?string $adquirenteReferencia = null): float
    {
        return match ($adquirenteReferencia) {
            'treeal' => (float) config('treeal.custo_fixo_transacao', 0.05),
            'fyhub' => (float) config('fyhub.custo_fixo_transacao', 0.04),
            'simpay' => (float) config('simpay.custo_fixo_transacao', 0.035),
            default => 0.0,
        };
    }

    /**
     * Custo total da adquirente para uma transação (R$).
     */
    public static function custoTransacao(float $amount, ?string $adquirenteReferencia = null): float
    {
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
     * Percentual mínimo da adquirente principal (legado — Treeal usa custo fixo).
     */
    public static function percentualPrincipal(): float
    {
        return self::percentualTransacao(self::adquirentePrincipal());
    }

    /**
     * Piso da taxa cobrada do usuário (em R$) no modo percentual.
     *
     * Garante que o valor cobrado nunca seja menor que o custo fixo da adquirente principal.
     */
    public static function pisoTaxa(float $amount): float
    {
        return self::custoFixoTransacao(self::adquirentePrincipal());
    }

    /**
     * Expressão SQL para custo da adquirente por linha (usa executor_ordem e amount).
     *
     * @param  bool  $cashOutTable  Tabela solicitacoes_cash_out não possui adquirente_ref.
     */
    public static function sqlCustoPorTransacaoExpr(string $amountColumn = 'amount', bool $cashOutTable = false): string
    {
        $custoTreeal = (float) config('treeal.custo_fixo_transacao', 0.05);
        $custoSimpay = (float) config('simpay.custo_fixo_transacao', 0.035);
        $custoFyhub = (float) config('fyhub.custo_fixo_transacao', 0.04);

        $simpayMatch = $cashOutTable
            ? "executor_ordem = 'simpay' OR executor_ordem = 'Adquirente PIX'"
            : "executor_ordem = 'simpay' OR executor_ordem = 'Adquirente PIX' OR adquirente_ref = 'Adquirente PIX'";

        return "(CASE
            WHEN executor_ordem = 'treeal' THEN {$custoTreeal}
            WHEN executor_ordem = 'fyhub' THEN {$custoFyhub}
            WHEN {$simpayMatch} THEN {$custoSimpay}
            ELSE {$custoSimpay}
        END)";
    }
}
