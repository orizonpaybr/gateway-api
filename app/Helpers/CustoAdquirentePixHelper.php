<?php

namespace App\Helpers;

/**
 * Custo fixo da rede/adquirente por transação PIX (split interno: taxa cliente − custo − afiliado).
 */
class CustoAdquirentePixHelper
{
    /**
     * Custo fixo por transação conforme a referência do adquirente (executor_ordem / resolve Pix).
     * Padrão: {@see config('app.custo_fixo_adquirente_pix')} (R$ 0,025). Adquirentes específicos podem
     * ser tratados aqui quando necessário.
     */
    public static function custoFixoTransacao(?string $adquirenteReferencia = null): float
    {
        return (float) config('app.custo_fixo_adquirente_pix', 0.025);
    }
}
