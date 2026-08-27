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
        config()->set('fyhub.custo_fixo_transacao', 0.10);
        config()->set('simpay.custo_fixo_transacao', 0.75);

        $this->assertSame(0.05, CustoAdquirentePixHelper::custoFixoTransacao('treeal'));
        // Fyhub ativa: custo config-driven (.env FYHUB_CUSTO_FIXO_TRANSACAO).
        $this->assertSame(0.10, CustoAdquirentePixHelper::custoFixoTransacao('fyhub'));
        // Simpay ativa: custo config-driven (.env SIMPAY_CUSTO_FIXO_TRANSACAO).
        $this->assertSame(0.75, CustoAdquirentePixHelper::custoFixoTransacao('simpay'));
        $this->assertSame(0.0, CustoAdquirentePixHelper::custoFixoTransacao(null));
        $this->assertSame(0.0, CustoAdquirentePixHelper::custoFixoTransacao('desconhecido'));
    }

    /** @test */
    public function paytler_cobra_percentual_e_nao_custo_fixo(): void
    {
        config()->set('paytler.custo_fixo_transacao', 0.0);
        config()->set('paytler.custo_percentual_transacao', 2.0);

        // Componente fixo zero; o custo vem do percentual (2% do valor).
        $this->assertSame(0.0, CustoAdquirentePixHelper::custoFixoTransacao('paytler'));
        $this->assertSame(2.0, CustoAdquirentePixHelper::percentualTransacao('paytler'));
        $this->assertEqualsWithDelta(2.00, CustoAdquirentePixHelper::custoTransacao(100.00, 'paytler'), 0.0001);
        $this->assertEqualsWithDelta(0.20, CustoAdquirentePixHelper::custoTransacao(10.00, 'paytler'), 0.0001);
        $this->assertEqualsWithDelta(20.00, CustoAdquirentePixHelper::custoTransacao(1000.00, 'paytler'), 0.0001);

        // SQL do custo por linha usa amount * 2% para paytler.
        $expr = CustoAdquirentePixHelper::sqlCustoPorTransacaoExpr('amount', true);
        $this->assertStringContainsString("executor_ordem = 'paytler'", $expr);
        $this->assertStringContainsString('* 2', $expr);
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
    public function piso_e_regra_da_plataforma_nao_depende_de_adquirente(): void
    {
        // Piso vem da config PRÓPRIA da plataforma, não do custo de nenhuma adquirente.
        config()->set('plataforma.taxa_minima_fixa', 0.30);
        config()->set('plataforma.taxa_minima_percentual', 0.0);
        // Mexer no custo da treeal NÃO pode afetar o piso (era o bug).
        config()->set('treeal.custo_fixo_transacao', 5.00);

        $this->assertSame(0.30, CustoAdquirentePixHelper::pisoTaxa(1000.00));
        $this->assertSame(0.0, CustoAdquirentePixHelper::percentualPrincipal());

        // Componente percentual do piso soma sobre o valor.
        config()->set('plataforma.taxa_minima_percentual', 1.0);
        $this->assertEqualsWithDelta(10.30, CustoAdquirentePixHelper::pisoTaxa(1000.00), 0.0001);
        $this->assertSame(1.0, CustoAdquirentePixHelper::percentualPrincipal());

        // Setting (tabela app, editável pelo admin) tem prioridade sobre a config.
        $setting = (object) ['taxa_minima_fixa' => 0.75, 'taxa_minima_percentual' => 0.0];
        $this->assertSame(0.75, CustoAdquirentePixHelper::pisoTaxa(1000.00, $setting));
        $this->assertSame(0.0, CustoAdquirentePixHelper::percentualPrincipal($setting));
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
