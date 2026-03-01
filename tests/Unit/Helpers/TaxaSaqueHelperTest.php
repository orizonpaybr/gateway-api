<?php

namespace Tests\Unit\Helpers;

use App\Helpers\TaxaSaqueHelper;
use App\Models\App;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testes do TaxaSaqueHelper::calcularTaxaSaque e calcularValorMaximoSaque
 *
 * Cobre: taxa global, personalizada, com/sem afiliado, validações, valor máximo.
 */
class TaxaSaqueHelperTest extends TestCase
{
    use RefreshDatabase;

    private App $setting;

    protected function setUp(): void
    {
        parent::setUp();
        config(['treeal.custo_fixo_por_transacao' => 0.04]);
        $this->setting = App::create([
            'taxa_fixa_padrao' => 1.00,
            'taxa_fixa_pix' => 1.00,
            'taxa_comissao_afiliado_padrao' => 0.50,
            'taxa_fixa_padrao_cash_out' => 0,
        ]);
    }

    /** @test */
    public function calcula_taxa_saque_global_sem_afiliado(): void
    {
        $user = User::factory()->create([
            'user_id' => 'user_saque_1',
            'username' => 'user_saque_1',
            'taxas_personalizadas_ativas' => false,
            'affiliate_id' => null,
        ]);

        $result = TaxaSaqueHelper::calcularTaxaSaque(50.00, $this->setting, $user, false, true);

        $this->assertEquals(1.00, $result['taxa_cash_out']);
        $this->assertEquals(50.00, $result['saque_liquido']);
        $this->assertEquals(51.00, $result['valor_total_descontar']);
        $this->assertEquals('GLOBAL_API_FIXA', $result['descricao']);
        $this->assertEquals(0.98, round($result['taxa_aplicacao'], 2));
        $this->assertEquals(0.00, $result['comissao_afiliado']);
    }

    /** @test */
    public function calcula_taxa_saque_personalizada(): void
    {
        $user = User::factory()->create([
            'user_id' => 'user_saque_2',
            'username' => 'user_saque_2',
            'taxas_personalizadas_ativas' => true,
            'taxa_fixa_pix' => 0.80,
            'affiliate_id' => null,
        ]);

        $result = TaxaSaqueHelper::calcularTaxaSaque(20.00, $this->setting, $user, false, true);

        $this->assertEquals(0.80, $result['taxa_cash_out']);
        $this->assertEquals(20.00, $result['saque_liquido']);
        $this->assertEquals(20.80, $result['valor_total_descontar']);
        $this->assertEquals('PERSONALIZADA_API_FIXA', $result['descricao']);
    }

    /** @test */
    public function calcula_taxa_saque_com_afiliado_comissao_padrao(): void
    {
        $affiliate = User::factory()->create([
            'user_id' => 'aff_saque',
            'username' => 'aff_saque',
            'comissao_afiliado_personalizada' => false,
        ]);
        $user = User::factory()->create([
            'user_id' => 'user_saque_aff',
            'username' => 'user_saque_aff',
            'affiliate_id' => $affiliate->id,
            'taxas_personalizadas_ativas' => false,
        ]);

        $result = TaxaSaqueHelper::calcularTaxaSaque(10.00, $this->setting, $user, false, true);

        $this->assertEquals(1.00, $result['taxa_cash_out']);
        $this->assertEquals(0.50, $result['comissao_afiliado']);
        $this->assertEquals(0.48, round($result['taxa_aplicacao'], 2));
    }

    /** @test */
    public function lanca_excecao_valor_negativo(): void
    {
        $user = User::factory()->create(['user_id' => 'u1', 'username' => 'u1']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('O valor do saque não pode ser negativo');

        TaxaSaqueHelper::calcularTaxaSaque(-5.00, $this->setting, $user);
    }

    /** @test */
    public function lanca_excecao_setting_nulo(): void
    {
        $user = User::factory()->create(['user_id' => 'u2', 'username' => 'u2']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Configurações do sistema são obrigatórias');

        TaxaSaqueHelper::calcularTaxaSaque(10.00, null, $user);
    }

    /** @test */
    public function lanca_excecao_usuario_nulo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Usuário é obrigatório para cálculo de taxa de saque');

        TaxaSaqueHelper::calcularTaxaSaque(10.00, $this->setting, null);
    }

    /** @test */
    public function calcular_valor_maximo_saque_considera_taxa_global(): void
    {
        $user = User::factory()->create([
            'user_id' => 'u_max',
            'username' => 'u_max',
            'taxas_personalizadas_ativas' => false,
        ]);

        $result = TaxaSaqueHelper::calcularValorMaximoSaque(100.00, $this->setting, $user, false);

        $this->assertEquals(99.00, $result['valor_maximo']); // 100 - 1 taxa
        $this->assertEquals(1.00, $result['taxa_total']);
    }

    /** @test */
    public function calcular_valor_maximo_saque_considera_taxa_personalizada(): void
    {
        $user = User::factory()->create([
            'user_id' => 'u_max2',
            'username' => 'u_max2',
            'taxas_personalizadas_ativas' => true,
            'taxa_fixa_pix' => 0.50,
        ]);

        $result = TaxaSaqueHelper::calcularValorMaximoSaque(50.00, $this->setting, $user, false);

        $this->assertEquals(49.50, $result['valor_maximo']);
        $this->assertEquals(0.50, $result['taxa_total']);
    }
}
