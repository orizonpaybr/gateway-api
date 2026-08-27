<?php

namespace App\Helpers;

/**
 * Custo da rede/adquirente por transação PIX (split interno: taxa cliente − custo − afiliado).
 *
 * Treeal / Fyhub / Simpay / FluxPayments / Paya55: custo fixo em R$ por transação.
 */
class CustoAdquirentePixHelper
{
    /**
     * Percentual cobrado pela adquirente sobre o valor da transação (ex.: 2 = 2%).
     * A maioria cobra custo fixo (retorna 0); a Paytler cobra 2% do valor.
     */
    public static function percentualTransacao(?string $adquirenteReferencia = null): float
    {
        return match ($adquirenteReferencia) {
            'paytler' => (float) config('paytler.custo_percentual_transacao', 2.0),
            default => 0.0,
        };
    }

    /**
     * Custo fixo por transação (R$).
     *
     * @see config('treeal.custo_fixo_transacao')
     * @see config('fyhub.custo_fixo_transacao')
     * @see config('simpay.custo_fixo_transacao')
     * @see config('fluxpayments.custo_fixo_transacao')
     * @see config('paya55.custo_fixo_transacao')
     */
    public static function custoFixoTransacao(?string $adquirenteReferencia = null): float
    {
        return match ($adquirenteReferencia) {
            'treeal' => (float) config('treeal.custo_fixo_transacao', 0.05),
            'fyhub' => (float) config('fyhub.custo_fixo_transacao', 0.10),
            'simpay' => (float) config('simpay.custo_fixo_transacao', 0.75),
            // Paytler é percentual (ver percentualTransacao); componente fixo = 0.
            'paytler' => (float) config('paytler.custo_fixo_transacao', 0.0),
            'fluxpayments' => (float) config('fluxpayments.custo_fixo_transacao', 0.09),
            'paya55' => (float) config('paya55.custo_fixo_transacao', 0.03),
            default => 0.0,
        };
    }

    /**
     * Custo total da adquirente para uma transação (R$) = componente fixo + percentual.
     * Fixo para a maioria; a Paytler entra pelo percentual (amount × 2%).
     */
    public static function custoTransacao(float $amount, ?string $adquirenteReferencia = null): float
    {
        $fixo = self::custoFixoTransacao($adquirenteReferencia);
        $percentual = self::percentualTransacao($adquirenteReferencia);

        return $fixo + ($amount * $percentual / 100);
    }

    /**
     * Referência da adquirente PRINCIPAL (rótulo informativo). NÃO define mais o piso
     * de taxa — o piso agora é regra própria da plataforma (config plataforma.*).
     * Configurável via .env (ADQUIRENTE_PRINCIPAL).
     */
    public static function adquirentePrincipal(): string
    {
        return (string) env('ADQUIRENTE_PRINCIPAL', 'treeal');
    }

    /**
     * Percentual mínimo cobrado do cliente — regra PRÓPRIA da plataforma, independente
     * de adquirente. Fonte: coluna `taxa_minima_percentual` da tabela `app` (editável
     * pelo admin); fallback pra config('plataforma.*') quando não houver setting/coluna.
     *
     * @param  \App\Models\App|null  $setting  Config global já carregada (evita query no hot path)
     */
    public static function percentualPrincipal($setting = null): float
    {
        return (float) (data_get($setting, 'taxa_minima_percentual')
            ?? config('plataforma.taxa_minima_percentual', 0.0));
    }

    /**
     * Piso da taxa cobrada do cliente (R$) — regra PRÓPRIA da Coratri, NÃO derivada de
     * nenhuma adquirente (adquirentes entram/saem; nossa política mínima é nossa).
     * Piso = componente fixo + percentual. Fonte: colunas `taxa_minima_*` da tabela `app`
     * (editável pelo admin); fallback pra config('plataforma.*'). A proteção de custo por
     * adquirente é separada (ver custoTransacao()).
     *
     * @param  \App\Models\App|null  $setting  Config global já carregada (evita query no hot path)
     */
    public static function pisoTaxa(float $amount, $setting = null): float
    {
        $fixo = (float) (data_get($setting, 'taxa_minima_fixa')
            ?? config('plataforma.taxa_minima_fixa', 0.05));
        $percentual = (float) (data_get($setting, 'taxa_minima_percentual')
            ?? config('plataforma.taxa_minima_percentual', 0.0));

        return $fixo + ($amount * $percentual / 100);
    }

    /**
     * Expressão SQL para custo da adquirente por linha (usa executor_ordem e amount).
     *
     * @param  bool  $cashOutTable  Tabela solicitacoes_cash_out não possui adquirente_ref.
     */
    public static function sqlCustoPorTransacaoExpr(string $amountColumn = 'amount', bool $cashOutTable = false): string
    {
        $custoTreeal = (float) config('treeal.custo_fixo_transacao', 0.05);
        $custoSimpay = (float) config('simpay.custo_fixo_transacao', 0.75);
        $custoFyhub = (float) config('fyhub.custo_fixo_transacao', 0.10);
        $custoFluxpayments = (float) config('fluxpayments.custo_fixo_transacao', 0.09);
        $custoPaya55 = (float) config('paya55.custo_fixo_transacao', 0.03);
        $custoPaytlerFixo = (float) config('paytler.custo_fixo_transacao', 0.0);
        $pctPaytler = (float) config('paytler.custo_percentual_transacao', 2.0);

        $simpayMatch = $cashOutTable
            ? "executor_ordem = 'simpay' OR executor_ordem = 'Adquirente PIX'"
            : "executor_ordem = 'simpay' OR executor_ordem = 'Adquirente PIX' OR adquirente_ref = 'Adquirente PIX'";

        return "(CASE
            WHEN executor_ordem = 'treeal' THEN {$custoTreeal}
            WHEN executor_ordem = 'fyhub' THEN {$custoFyhub}
            WHEN executor_ordem = 'fluxpayments' THEN {$custoFluxpayments}
            WHEN executor_ordem = 'paya55' THEN {$custoPaya55}
            WHEN executor_ordem = 'paytler' THEN ({$custoPaytlerFixo} + {$amountColumn} * {$pctPaytler} / 100)
            WHEN {$simpayMatch} THEN {$custoSimpay}
            ELSE {$custoSimpay}
        END)";
    }
}
