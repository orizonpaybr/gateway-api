<?php

namespace Tests\Unit\Services;

use App\Models\App;
use App\Services\GatewaySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Testes do GatewaySettingsService
 *
 * Cobre: getSettings (com cache e criação padrão), updateSettings,
 * formatSettingsResponse, clearCache, regras de validação.
 */
class GatewaySettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private GatewaySettingsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(GatewaySettingsService::class);
        Cache::flush();
    }

    /** @test */
    public function get_settings_cria_registro_padrao_se_nao_existir(): void
    {
        $this->assertDatabaseMissing('app', ['id' => 1]);

        $settings = $this->service->getSettings();

        $this->assertInstanceOf(App::class, $settings);
        $this->assertEquals(1.00, (float) $settings->taxa_fixa_padrao);
        $this->assertEquals(1.00, (float) $settings->taxa_fixa_pix);
        $this->assertDatabaseHas('app', ['id' => $settings->id]);
    }

    /** @test */
    public function get_settings_usa_cache(): void
    {
        App::create([
            'taxa_fixa_padrao' => 2.50,
            'taxa_fixa_pix' => 1.50,
            'taxa_fixa_padrao_cash_out' => 0,
        ]);

        $first = $this->service->getSettings();
        $second = $this->service->getSettings();

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(2.50, (float) $first->taxa_fixa_padrao);
    }

    /** @test */
    public function update_settings_atualiza_e_invalida_cache(): void
    {
        App::create([
            'taxa_fixa_padrao' => 1.00,
            'taxa_fixa_pix' => 1.00,
            'taxa_fixa_padrao_cash_out' => 0,
        ]);

        $updated = $this->service->updateSettings([
            'taxa_fixa_deposito' => 1.50,
            'taxa_fixa_pix' => 0.90,
            'taxa_comissao_afiliado_padrao' => 0.60,
        ]);

        $this->assertEquals(1.50, (float) $updated->taxa_fixa_padrao);
        $this->assertEquals(0.90, (float) $updated->taxa_fixa_pix);
        $this->assertEquals(0.60, (float) $updated->taxa_comissao_afiliado_padrao);

        // Após update, getSettings deve buscar do banco (cache foi limpo)
        $fresh = $this->service->getSettings();
        $this->assertEquals(1.50, (float) $fresh->taxa_fixa_padrao);
    }

    /** @test */
    public function format_settings_response_retorna_array_com_taxas_e_relatorios(): void
    {
        $app = App::create([
            'taxa_fixa_padrao' => 1.20,
            'taxa_fixa_pix' => 0.80,
            'taxa_comissao_afiliado_padrao' => 0.50,
            'taxa_fixa_padrao_cash_out' => 0,
        ]);

        $formatted = $this->service->formatSettingsResponse($app);

        $this->assertIsArray($formatted);
        $this->assertEquals(1.20, $formatted['taxa_fixa_deposito']);
        $this->assertEquals(0.80, $formatted['taxa_fixa_pix']);
        $this->assertEquals(0.50, $formatted['taxa_comissao_afiliado_padrao']);
        $this->assertArrayHasKey('relatorio_entradas_mostrar_meio', $formatted);
        $this->assertArrayHasKey('relatorio_saidas_mostrar_valor', $formatted);
        $this->assertArrayHasKey('global_ips', $formatted);
    }

    /** @test */
    public function clear_cache_remove_cache_de_configuracoes(): void
    {
        $this->service->getSettings();
        $this->assertNotNull(Cache::get('app_settings'));

        $this->service->clearCache();
        $this->assertNull(Cache::get('app_settings'));
    }

    /** @test */
    public function validation_rules_incluem_taxas_e_relatorios(): void
    {
        $rules = GatewaySettingsService::getValidationRules();

        $this->assertArrayHasKey('taxa_fixa_deposito', $rules);
        $this->assertArrayHasKey('taxa_fixa_pix', $rules);
        $this->assertArrayHasKey('taxa_comissao_afiliado_padrao', $rules);
        $this->assertStringContainsString('numeric', $rules['taxa_fixa_deposito']);
        $this->assertArrayHasKey('global_ips', $rules);
    }
}
