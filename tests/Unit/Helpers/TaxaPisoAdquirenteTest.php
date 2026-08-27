<?php

namespace Tests\Unit\Helpers;

use App\Helpers\TaxaFlexivelHelper;
use App\Models\User;
use Tests\TestCase;

/**
 * Piso da taxa pelo custo REAL da adquirente ativa.
 *
 * Garante que um cliente com taxa FIXA (centavos) roteado numa adquirente
 * PERCENTUAL (Paytler 2%) nunca seja cobrado menos que o custo — protege a margem.
 * User em memória (sem user_id) para o helper não recarregar do banco.
 */
class TaxaPisoAdquirenteTest extends TestCase
{
    private function userTaxaFixa(float $taxaFixa): User
    {
        // Sem user_id => calcularTaxaDeposito não recarrega do banco.
        return new User([
            'taxas_personalizadas_ativas' => true,
            'taxa_modo_percentual' => false,
            'taxa_fixa_deposito' => $taxaFixa,
            'affiliate_id' => null,
        ]);
    }

    /** @test */
    public function taxa_fixa_em_adquirente_percentual_e_floorada_no_custo(): void
    {
        config()->set('paytler.custo_fixo_transacao', 0.0);
        config()->set('paytler.custo_percentual_transacao', 2.0);

        $setting = (object) ['taxa_fixa_padrao' => 1.00];
        $user = $this->userTaxaFixa(0.50);

        // R$1.000 * 2% = R$20 de custo. Taxa fixa R$0,50 é floorada para R$20.
        $r = TaxaFlexivelHelper::calcularTaxaDeposito(1000.00, $setting, $user, 'paytler');
        $this->assertEqualsWithDelta(20.00, (float) $r['taxa_cash_in'], 0.001);
        $this->assertEqualsWithDelta(20.00, (float) $r['taxa_adquirente'], 0.001);
        // Sem prejuízo: lucro nunca negativo.
        $this->assertGreaterThanOrEqual(0.0, (float) $r['taxa_aplicacao']);
    }

    /** @test */
    public function taxa_fixa_acima_do_custo_nao_e_alterada(): void
    {
        config()->set('paytler.custo_fixo_transacao', 0.0);
        config()->set('paytler.custo_percentual_transacao', 2.0);

        $setting = (object) ['taxa_fixa_padrao' => 1.00];
        $user = $this->userTaxaFixa(0.50);

        // R$10 * 2% = R$0,20. Taxa fixa R$0,50 > custo, mantém R$0,50.
        $r = TaxaFlexivelHelper::calcularTaxaDeposito(10.00, $setting, $user, 'paytler');
        $this->assertEqualsWithDelta(0.50, (float) $r['taxa_cash_in'], 0.001);
    }

    /** @test */
    public function adquirente_fixa_nao_sofre_piso_percentual(): void
    {
        config()->set('treeal.custo_fixo_transacao', 0.05);

        $setting = (object) ['taxa_fixa_padrao' => 1.00];
        $user = $this->userTaxaFixa(0.50);

        // Treeal é custo fixo R$0,05 << R$0,50: taxa fixa do cliente é mantida.
        $r = TaxaFlexivelHelper::calcularTaxaDeposito(1000.00, $setting, $user, 'treeal');
        $this->assertEqualsWithDelta(0.50, (float) $r['taxa_cash_in'], 0.001);
    }
}
