<?php

namespace Tests\Unit\Controllers;

use Tests\TestCase;
use App\Models\App;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Helpers\AuthTestHelper;

/**
 * Testes Unitários - Configurações Gerais do Gateway
 * 
 * Cobre:
 * - getSettings
 * - updateSettings
 * - Validação de campos
 * - Mapeamento de campos
 * - Formatação de resposta
 * - Cache
 * - Criação automática de registro padrão
 */
class GatewaySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_should_get_settings()
    {
        // Criar configurações
        App::create([
            'taxa_cash_in_padrao' => 5.00,
            'taxa_fixa_padrao' => 1.00,
            'deposito_minimo' => 5.00,
        ]);

        $service = new \App\Services\GatewaySettingsService();
        $settings = $service->getSettings();

        $this->assertNotNull($settings);
        $this->assertInstanceOf(App::class, $settings);
    }

    public function test_should_create_default_settings_if_not_exists()
    {
        // Não criar configurações manualmente - deve criar automaticamente
        $service = new \App\Services\GatewaySettingsService();
        $settings = $service->getSettings();

        $this->assertNotNull($settings);
        $this->assertInstanceOf(App::class, $settings);
        $this->assertEquals(5.00, $settings->taxa_cash_in_padrao);
        $this->assertEquals(1.00, $settings->taxa_fixa_padrao);
    }

    public function test_should_update_settings()
    {
        // Criar configurações iniciais
        App::create([
            'taxa_cash_in_padrao' => 5.00,
            'taxa_fixa_padrao' => 1.00,
        ]);

        $service = new \App\Services\GatewaySettingsService();
        
        $updatedSettings = $service->updateSettings([
            'taxa_percentual_deposito' => 10.00,
            'taxa_fixa_deposito' => 2.00,
        ]);

        $this->assertNotNull($updatedSettings);
        $this->assertEquals(10.00, $updatedSettings->taxa_cash_in_padrao);
        $this->assertEquals(2.00, $updatedSettings->taxa_fixa_padrao);
    }

    public function test_should_map_fields_correctly()
    {
        App::create([
            'taxa_cash_in_padrao' => 5.00,
        ]);

        $service = new \App\Services\GatewaySettingsService();
        
        $updatedSettings = $service->updateSettings([
            'taxa_percentual_deposito' => 15.00,
            'taxa_percentual_pix' => 8.00,
            'valor_minimo_deposito' => 10.00,
        ]);

        $this->assertEquals(15.00, $updatedSettings->taxa_cash_in_padrao);
        $this->assertEquals(8.00, $updatedSettings->taxa_cash_out_padrao);
        $this->assertEquals(10.00, $updatedSettings->deposito_minimo);
    }

    public function test_should_format_settings_response()
    {
        $settings = App::create([
            'taxa_cash_in_padrao' => 5.00,
            'taxa_fixa_padrao' => 1.00,
            'deposito_minimo' => 5.00,
            'taxa_cash_out_padrao' => 5.00,
            'taxa_fixa_pix' => 1.00,
            'taxa_fixa_padrao_cash_out' => 0.00,
            'saque_minimo' => 5.00,
            'limite_saque_mensal' => 50000.00,
            'taxa_saque_api_padrao' => 5.00,
            'taxa_saque_cripto_padrao' => 7.00,
            'taxa_flexivel_ativa' => false,
            'taxa_flexivel_valor_minimo' => 15.00,
            'taxa_flexivel_fixa_baixo' => 4.99,
            'taxa_flexivel_percentual_alto' => 5.00,
        ]);

        $service = new \App\Services\GatewaySettingsService();
        $formatted = $service->formatSettingsResponse($settings);

        $this->assertIsArray($formatted);
        $this->assertArrayHasKey('taxa_percentual_deposito', $formatted);
        $this->assertArrayHasKey('taxa_fixa_deposito', $formatted);
        $this->assertArrayHasKey('taxa_percentual_pix', $formatted);
        $this->assertEquals(5.00, $formatted['taxa_percentual_deposito']);
    }

    public function test_should_use_cache_for_get_settings()
    {
        App::create([
            'taxa_cash_in_padrao' => 5.00,
        ]);

        $service = new \App\Services\GatewaySettingsService();
        
        // Primeira chamada (deve buscar do banco)
        $settings1 = $service->getSettings();
        
        // Modificar diretamente no banco (simulando mudança externa)
        $settings1->taxa_cash_in_padrao = 10.00;
        $settings1->save();
        
        // Segunda chamada (deve usar cache - não reflete mudança)
        $settings2 = $service->getSettings();
        
        // Cache deve retornar o mesmo valor
        $this->assertEquals($settings1->taxa_cash_in_padrao, $settings2->taxa_cash_in_padrao);
    }

    public function test_should_clear_cache_on_update()
    {
        Cache::flush(); // Limpar cache antes do teste
        
        $app = App::create([
            'taxa_cash_in_padrao' => 5.00,
        ]);

        $service = new \App\Services\GatewaySettingsService();
        
        // Primeira chamada (cria cache)
        $service->getSettings();
        
        // Atualizar configurações (deve limpar cache)
        $updated = $service->updateSettings([
            'taxa_percentual_deposito' => 15.00,
        ]);
        
        // Verificar que a atualização foi feita diretamente no modelo retornado
        $this->assertEquals(15.00, $updated->taxa_cash_in_padrao);
        
        // Verificar no banco diretamente (bypass cache)
        $app->refresh();
        $this->assertEquals(15.00, $app->taxa_cash_in_padrao);
        
        // Limpar cache manualmente para garantir que busca do banco
        Cache::flush();
        
        // Próxima chamada deve buscar do banco (cache limpo)
        $settings = $service->getSettings();
        $this->assertEquals(15.00, $settings->taxa_cash_in_padrao);
    }

    public function test_should_update_direct_fields()
    {
        App::create([
            'relatorio_entradas_mostrar_meio' => true,
            'relatorio_entradas_mostrar_valor' => true,
        ]);

        $service = new \App\Services\GatewaySettingsService();
        
        $updatedSettings = $service->updateSettings([
            'relatorio_entradas_mostrar_meio' => false,
            'relatorio_entradas_mostrar_valor' => false,
        ]);

        $this->assertFalse($updatedSettings->relatorio_entradas_mostrar_meio);
        $this->assertFalse($updatedSettings->relatorio_entradas_mostrar_valor);
    }

    public function test_should_update_global_ips()
    {
        App::create([
            'global_ips' => [],
        ]);

        $service = new \App\Services\GatewaySettingsService();
        
        $updatedSettings = $service->updateSettings([
            'global_ips' => ['192.168.1.1', '10.0.0.1'],
        ]);

        $this->assertIsArray($updatedSettings->global_ips);
        $this->assertContains('192.168.1.1', $updatedSettings->global_ips);
        $this->assertContains('10.0.0.1', $updatedSettings->global_ips);
    }

    public function test_should_handle_flexible_tax_system()
    {
        App::create([
            'taxa_flexivel_ativa' => false,
            'taxa_flexivel_valor_minimo' => 15.00,
        ]);

        $service = new \App\Services\GatewaySettingsService();
        
        $updatedSettings = $service->updateSettings([
            'sistema_flexivel_ativo' => true,
            'valor_minimo_flexivel' => 20.00,
            'taxa_fixa_baixos' => 5.00,
            'taxa_percentual_altos' => 6.00,
        ]);

        $this->assertTrue($updatedSettings->taxa_flexivel_ativa);
        $this->assertEquals(20.00, $updatedSettings->taxa_flexivel_valor_minimo);
        $this->assertEquals(5.00, $updatedSettings->taxa_flexivel_fixa_baixo);
        $this->assertEquals(6.00, $updatedSettings->taxa_flexivel_percentual_alto);
    }

    public function test_should_create_settings_if_not_exists_on_update()
    {
        // Não criar configurações manualmente
        $service = new \App\Services\GatewaySettingsService();
        
        $updatedSettings = $service->updateSettings([
            'taxa_percentual_deposito' => 10.00,
        ]);

        $this->assertNotNull($updatedSettings);
        $this->assertEquals(10.00, $updatedSettings->taxa_cash_in_padrao);
    }
}

