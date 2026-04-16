<?php

namespace App\Helpers;

/**
 * Custo fixo da rede/adquirente por transação PIX (split interno: taxa cliente − custo − afiliado).
 */
class CustoAdquirentePixHelper
{
    /**
     * Custo fixo por transação conforme a referência do adquirente (executor_ordem / resolve Pix).
     * Apenas SIMPAY possui custo configurado em {@see config('simpay.custo_fixo_transacao')}.
     */
    public static function custoFixoTransacao(?string $adquirenteReferencia = null): float
    {
        if ($adquirenteReferencia === 'simpay') {
            return (float) config('simpay.custo_fixo_transacao', 0.035);
        }

        return 0.0;
    }
}
