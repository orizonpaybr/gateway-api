<?php

namespace Tests\Unit\Helpers;

use App\Helpers\CustoAdquirentePixHelper;
use Tests\TestCase;

/**
 * Testes do CustoAdquirentePixHelper
 *
 * Cobre: custo fixo/percentual por adquirente, adquirente principal e piso.
 */
class CustoAdquirentePixHelperTest extends TestCase
{
    /** @test */
    public function custo_fixo_transacao_retorna_valor_por_adquirente(): void
    {
        config()->set('fyhub.custo_fixo_transacao', 0.04);
        config()->set('simpay.custo_fixo_transacao', 0.035);

        $this->assertSame(0.0, CustoAdquirentePixHelper::custoFixoTransacao('treeal'));
        $this->assertSame(0.04, CustoAdquirentePixHelper::custoFixoTransacao('fyhub'));
        $this->assertSame(0.035, CustoAdquirentePixHelper::custoFixoTransacao('simpay'));
        $this->assertSame(0.0, CustoAdquirentePixHelper::custoFixoTransacao(null));
        $this->assertSame(0.0, CustoAdquirentePixHelper::custoFixoTransacao('desconhecido'));
    }

    /** @test */
    public function treeal_cobra_percentual_sobre_o_valor(): void
    {
        config()->set('treeal.taxa_percentual_transacao', 1.0);

        $this->assertSame(1.0, CustoAdquirentePixHelper::percentualTransacao('treeal'));
        $this->assertEqualsWithDelta(0.10, CustoAdquirentePixHelper::custoTransacao(10.00, 'treeal'), 0.0001);
        $this->assertEqualsWithDelta(1.00, CustoAdquirentePixHelper::custoTransacao(100.00, 'treeal'), 0.0001);
    }

    /** @test */
    public function adquirente_principal_padrao_e_treeal(): void
    {
        $this->assertSame('treeal', CustoAdquirentePixHelper::adquirentePrincipal());
    }

    /** @test */
    public function piso_taxa_usa_percentual_da_adquirente_principal(): void
    {
        config()->set('treeal.taxa_percentual_transacao', 1.0);

        // 1% de R$ 10,00 = R$ 0,10
        $this->assertEqualsWithDelta(0.10, CustoAdquirentePixHelper::pisoTaxa(10.00), 0.0001);
        $this->assertSame(1.0, CustoAdquirentePixHelper::percentualPrincipal());
    }
}
