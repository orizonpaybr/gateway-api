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
        config()->set('treeal.custo_fixo_transacao', 0.05);
        config()->set('fyhub.custo_fixo_transacao', 0.04);
        config()->set('simpay.custo_fixo_transacao', 0.035);

        $this->assertSame(0.05, CustoAdquirentePixHelper::custoFixoTransacao('treeal'));
        $this->assertSame(0.04, CustoAdquirentePixHelper::custoFixoTransacao('fyhub'));
        $this->assertSame(0.035, CustoAdquirentePixHelper::custoFixoTransacao('simpay'));
        $this->assertSame(0.0, CustoAdquirentePixHelper::custoFixoTransacao(null));
        $this->assertSame(0.0, CustoAdquirentePixHelper::custoFixoTransacao('desconhecido'));
    }

    /** @test */
    public function treeal_cobra_custo_fixo_por_transacao(): void
    {
        config()->set('treeal.custo_fixo_transacao', 0.05);

        $this->assertSame(0.0, CustoAdquirentePixHelper::percentualTransacao('treeal'));
        $this->assertEqualsWithDelta(0.05, CustoAdquirentePixHelper::custoTransacao(10.00, 'treeal'), 0.0001);
        $this->assertEqualsWithDelta(0.05, CustoAdquirentePixHelper::custoTransacao(100.00, 'treeal'), 0.0001);
    }

    /** @test */
    public function adquirente_principal_padrao_e_treeal(): void
    {
        $this->assertSame('treeal', CustoAdquirentePixHelper::adquirentePrincipal());
    }

    /** @test */
    public function sql_custo_cash_out_nao_referencia_adquirente_ref(): void
    {
        $expr = CustoAdquirentePixHelper::sqlCustoPorTransacaoExpr('amount', true);

        $this->assertStringNotContainsString('adquirente_ref', $expr);
        $this->assertStringContainsString('executor_ordem', $expr);
    }

    /** @test */
    public function sql_custo_depositos_inclui_adquirente_ref(): void
    {
        $expr = CustoAdquirentePixHelper::sqlCustoPorTransacaoExpr('amount', false);

        $this->assertStringContainsString('adquirente_ref', $expr);
    }
}
