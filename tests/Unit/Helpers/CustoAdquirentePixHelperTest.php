<?php

namespace Tests\Unit\Helpers;

use App\Helpers\CustoAdquirentePixHelper;
use Tests\TestCase;

/**
 * Testes do CustoAdquirentePixHelper
 *
 * Cobre: custo fixo por adquirente, adquirente principal e piso em centavos.
 */
class CustoAdquirentePixHelperTest extends TestCase
{
    /** @test */
    public function custo_fixo_transacao_retorna_valor_por_adquirente(): void
    {
        config()->set('treeal.custo_fixo_transacao', 0.03);
        config()->set('fyhub.custo_fixo_transacao', 0.04);
        config()->set('simpay.custo_fixo_transacao', 0.035);

        $this->assertSame(0.03, CustoAdquirentePixHelper::custoFixoTransacao('treeal'));
        $this->assertSame(0.04, CustoAdquirentePixHelper::custoFixoTransacao('fyhub'));
        $this->assertSame(0.035, CustoAdquirentePixHelper::custoFixoTransacao('simpay'));
        $this->assertSame(0.0, CustoAdquirentePixHelper::custoFixoTransacao(null));
        $this->assertSame(0.0, CustoAdquirentePixHelper::custoFixoTransacao('desconhecido'));
    }

    /** @test */
    public function adquirente_principal_padrao_e_treeal(): void
    {
        $this->assertSame('treeal', CustoAdquirentePixHelper::adquirentePrincipal());
    }

    /** @test */
    public function piso_centavos_usa_custo_da_adquirente_principal(): void
    {
        config()->set('treeal.custo_fixo_transacao', 0.03);

        // Sem override de ADQUIRENTE_PRINCIPAL, o piso é o custo fixo da Treeal.
        $this->assertSame(0.03, CustoAdquirentePixHelper::pisoCentavos());
    }
}
