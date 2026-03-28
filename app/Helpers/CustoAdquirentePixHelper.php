<?php

namespace App\Helpers;

/**
 * Custo fixo da rede/adquirente por transação PIX (split interno: taxa cliente − custo − afiliado).
 */
class CustoAdquirentePixHelper
{
    /**
     * Custo fixo por transação conforme a referência do adquirente (executor_ordem / resolve Pix).
     * MagenPay: R$ 0,04; demais: {@see config('app.custo_fixo_adquirente_pix')} (padrão R$ 0,025).
     */
    public static function custoFixoTransacao(?string $adquirenteReferencia = null): float
    {
        $ref = $adquirenteReferencia !== null ? strtolower(trim($adquirenteReferencia)) : '';

        if ($ref === 'magenpay') {
            return (float) config('magenpay.custo_fixo_transacao', 0.04);
        }

        return (float) config('app.custo_fixo_adquirente_pix', 0.025);
    }
}
