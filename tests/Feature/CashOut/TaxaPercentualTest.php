<?php

namespace Tests\Feature\CashOut;

use App\Constants\UserPermission;
use App\Constants\UserStatus;
use App\Helpers\TaxaFlexivelHelper;
use App\Helpers\TaxaSaqueHelper;
use App\Models\App;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testes do modo de cobrança por PORCENTAGEM (individual, exclusivo da taxa fixa).
 *
 * Regras cobertas:
 * - Quando taxa_modo_percentual = true, a taxa é um % sobre o valor (substitui a fixa).
 * - O valor cobrado nunca fica abaixo do piso (1% da adquirente principal/Treeal).
 * - Com taxa_modo_percentual = false, a taxa fixa em reais continua valendo (comportamento atual).
 */
class TaxaPercentualTest extends TestCase
{
    use RefreshDatabase;

    private App $setting;

    protected function setUp(): void
    {
        parent::setUp();

        // Piso determinístico: Treeal 1% sobre o valor
        config()->set('treeal.taxa_percentual_transacao', 1.0);

        $this->setting = App::factory()->create([
            'taxa_fixa_padrao' => 1.00,
            'taxa_fixa_pix' => 1.00,
        ]);
    }

    private function makeUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'status' => UserStatus::ACTIVE,
            'permission' => UserPermission::CLIENT,
            'taxas_personalizadas_ativas' => true,
            'taxa_modo_percentual' => true,
            'taxa_percentual_deposito' => 2.00,
            'taxa_percentual_pix' => 2.00,
        ], $attrs));
    }

    /** @test */
    public function deposito_percentual_cobra_percentual_sobre_o_valor(): void
    {
        $user = $this->makeUser();

        $r = TaxaFlexivelHelper::calcularTaxaDeposito(10.00, $this->setting, $user, 'treeal');

        $this->assertSame('PERSONALIZADA_PERCENTUAL', $r['descricao']);
        $this->assertTrue($r['modo_percentual']);
        $this->assertEqualsWithDelta(0.20, $r['taxa_cash_in'], 0.0001);
        $this->assertEqualsWithDelta(9.80, $r['deposito_liquido'], 0.0001);
        $this->assertEqualsWithDelta(0.10, $r['taxa_adquirente'], 0.0001);
    }

    /** @test */
    public function deposito_percentual_respeita_piso_da_adquirente(): void
    {
        $user = $this->makeUser(['taxa_percentual_deposito' => 0.50]);

        // 0,5% de R$ 10,00 = R$ 0,05, abaixo do piso de 1% (R$ 0,10) → cobra o piso
        $r = TaxaFlexivelHelper::calcularTaxaDeposito(10.00, $this->setting, $user, 'treeal');

        $this->assertEqualsWithDelta(0.10, $r['taxa_cash_in'], 0.0001);
    }

    /** @test */
    public function saque_percentual_cobra_percentual_sobre_o_valor(): void
    {
        $user = $this->makeUser();

        $r = TaxaSaqueHelper::calcularTaxaSaque(10.00, $this->setting, $user, true, false, 'treeal');

        $this->assertTrue($r['modo_percentual']);
        $this->assertEqualsWithDelta(0.20, $r['taxa_cash_out'], 0.0001);
        $this->assertEqualsWithDelta(10.20, $r['valor_total_descontar'], 0.0001);
        $this->assertEqualsWithDelta(0.10, $r['taxa_adquirente'], 0.0001);
    }

    /** @test */
    public function saque_percentual_respeita_piso_da_adquirente(): void
    {
        $user = $this->makeUser(['taxa_percentual_pix' => 0.50]);

        // 0,5% de R$ 10,00 = R$ 0,05, abaixo do piso de 1% (R$ 0,10) → cobra o piso
        $r = TaxaSaqueHelper::calcularTaxaSaque(10.00, $this->setting, $user, true, false, 'treeal');

        $this->assertEqualsWithDelta(0.10, $r['taxa_cash_out'], 0.0001);
    }

    /** @test */
    public function modo_fixo_continua_usando_taxa_fixa_em_reais(): void
    {
        $user = $this->makeUser([
            'taxa_modo_percentual' => false,
            'taxa_fixa_deposito' => 0.90,
        ]);

        $r = TaxaFlexivelHelper::calcularTaxaDeposito(10.00, $this->setting, $user, 'treeal');

        $this->assertFalse($r['modo_percentual']);
        $this->assertSame('PERSONALIZADA_FIXA', $r['descricao']);
        $this->assertEqualsWithDelta(0.90, $r['taxa_cash_in'], 0.0001);
        $this->assertEqualsWithDelta(0.10, $r['taxa_adquirente'], 0.0001);
    }
}
