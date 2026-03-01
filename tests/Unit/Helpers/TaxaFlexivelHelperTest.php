<?php

namespace Tests\Unit\Helpers;

use App\Helpers\TaxaFlexivelHelper;
use App\Models\App;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Testes do TaxaFlexivelHelper::calcularTaxaDeposito
 *
 * Cobre: taxa global, taxa personalizada, com/sem afiliado, validações.
 */
class TaxaFlexivelHelperTest extends TestCase
{
    use RefreshDatabase;

    private App $setting;

    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
        config(['treeal.custo_fixo_por_transacao' => 0.04]);
        $this->setting = App::create([
            'taxa_fixa_padrao' => 1.00,
            'taxa_fixa_pix' => 1.00,
            'taxa_comissao_afiliado_padrao' => 0.50,
            'taxa_fixa_padrao_cash_out' => 0,
        ]);
    }

    /** @test */
    public function calcula_taxa_deposito_com_taxa_global_sem_usuario(): void
    {
        $result = TaxaFlexivelHelper::calcularTaxaDeposito(100.00, $this->setting, null);

        $this->assertEquals(1.00, $result['taxa_cash_in']);
        $this->assertEquals(99.00, $result['deposito_liquido']);
        $this->assertEquals('GLOBAL_FIXA', $result['descricao']);
        $this->assertEquals(0.04, $result['taxa_adquirente']);
        // taxa_aplicacao = taxa fixa - custo Treeal - comissão afiliado = 1.00 - 0.04 - 0 = 0.96
        $this->assertEquals(0.96, $result['taxa_aplicacao']);
        $this->assertEquals(0.00, $result['comissao_afiliado']);
    }

    /** @test */
    public function calcula_taxa_deposito_com_taxa_personalizada_ativa(): void
    {
        $user = User::factory()->create([
            'user_id' => 'user_tax_custom',
            'username' => 'user_tax_custom',
            'taxas_personalizadas_ativas' => true,
            'taxa_fixa_deposito' => 0.90,
            'affiliate_id' => null,
        ]);

        $result = TaxaFlexivelHelper::calcularTaxaDeposito(50.00, $this->setting, $user);

        $this->assertEquals(0.90, $result['taxa_cash_in']);
        $this->assertEquals(49.10, $result['deposito_liquido']);
        $this->assertEquals('PERSONALIZADA_FIXA', $result['descricao']);
    }

    /** @test */
    public function calcula_taxa_deposito_com_afiliado_usando_comissao_padrao(): void
    {
        $affiliate = User::factory()->create([
            'user_id' => 'affiliate_1',
            'username' => 'affiliate_1',
            'comissao_afiliado_personalizada' => false,
            'taxa_comissao_afiliado' => null,
        ]);
        $user = User::factory()->create([
            'user_id' => 'user_aff',
            'username' => 'user_aff',
            'affiliate_id' => $affiliate->id,
            'taxas_personalizadas_ativas' => false,
        ]);

        $result = TaxaFlexivelHelper::calcularTaxaDeposito(10.00, $this->setting, $user);

        $this->assertEquals(1.00, $result['taxa_cash_in']);
        $this->assertEquals(0.50, $result['comissao_afiliado']);
        $this->assertEquals(0.04, $result['taxa_adquirente']);
        $this->assertEquals(0.46, round($result['taxa_aplicacao'], 2)); // 1 - 0.04 - 0.50
    }

    /** @test */
    public function calcula_taxa_deposito_com_afiliado_comissao_personalizada(): void
    {
        $affiliate = User::factory()->create([
            'user_id' => 'affiliate_2',
            'username' => 'affiliate_2',
            'comissao_afiliado_personalizada' => true,
            'taxa_comissao_afiliado' => 0.30,
        ]);
        $user = User::factory()->create([
            'user_id' => 'user_aff2',
            'username' => 'user_aff2',
            'affiliate_id' => $affiliate->id,
            'taxas_personalizadas_ativas' => false,
        ]);

        $result = TaxaFlexivelHelper::calcularTaxaDeposito(10.00, $this->setting, $user);

        $this->assertEquals(1.00, $result['taxa_cash_in']);
        $this->assertEquals(0.30, $result['comissao_afiliado']);
        $this->assertEquals(0.66, round($result['taxa_aplicacao'], 2)); // 1 - 0.04 - 0.30
    }

    /** @test */
    public function lanca_excecao_para_valor_negativo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('O valor do depósito não pode ser negativo');

        TaxaFlexivelHelper::calcularTaxaDeposito(-10.00, $this->setting, null);
    }

    /** @test */
    public function lanca_excecao_se_setting_nulo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Configurações do sistema são obrigatórias');

        TaxaFlexivelHelper::calcularTaxaDeposito(10.00, null, null);
    }

    /** @test */
    public function deposito_liquido_nao_fica_negativo_com_taxa_maior_que_valor(): void
    {
        $this->setting->update(['taxa_fixa_padrao' => 5.00]);
        $result = TaxaFlexivelHelper::calcularTaxaDeposito(3.00, $this->setting->fresh(), null);

        $this->assertEquals(5.00, $result['taxa_cash_in']);
        $this->assertEquals(0.00, $result['deposito_liquido']);
    }
}
