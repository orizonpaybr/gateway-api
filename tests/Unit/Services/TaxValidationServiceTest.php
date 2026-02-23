<?php

namespace Tests\Unit\Services;

use App\Services\TaxValidationService;
use Tests\TestCase;

/**
 * Testes do TaxValidationService
 *
 * Cobre: validateGlobalTaxes, validateIndividualTaxes, validateTaxConsistency, sanitizeTaxData.
 */
class TaxValidationServiceTest extends TestCase
{
    /** @test */
    public function validate_global_taxes_aceita_valores_validos(): void
    {
        $validator = TaxValidationService::validateGlobalTaxes([
            'taxa_fixa_deposito' => 1.00,
            'taxa_fixa_pix' => 0.90,
            'taxa_comissao_afiliado_padrao' => 0.50,
        ]);

        $this->assertFalse($validator->fails());
    }

    /** @test */
    public function validate_global_taxes_rejeita_taxa_negativa(): void
    {
        $validator = TaxValidationService::validateGlobalTaxes([
            'taxa_fixa_deposito' => -1,
            'taxa_fixa_pix' => 1.00,
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('taxa_fixa_deposito', $validator->errors()->toArray());
    }

    /** @test */
    public function validate_individual_taxes_aceita_taxas_personalizadas(): void
    {
        $validator = TaxValidationService::validateIndividualTaxes([
            'taxas_personalizadas_ativas' => true,
            'taxa_fixa_deposito' => 0.80,
            'taxa_fixa_pix' => 0.70,
            'taxa_comissao_afiliado' => 0.40,
            'comissao_afiliado_personalizada' => true,
        ]);

        $this->assertFalse($validator->fails());
    }

    /** @test */
    public function validate_tax_consistency_retorna_valid_para_dados_ok(): void
    {
        $result = TaxValidationService::validateTaxConsistency([
            'taxa_fixa_deposito' => 1.00,
            'taxa_fixa_pix' => 0.90,
        ]);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function validate_tax_consistency_retorna_erro_para_taxa_deposito_negativa(): void
    {
        $result = TaxValidationService::validateTaxConsistency([
            'taxa_fixa_deposito' => -0.50,
            'taxa_fixa_pix' => 1.00,
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('depósito', $result['errors'][0]);
    }

    /** @test */
    public function validate_tax_consistency_retorna_erro_para_taxa_pix_negativa(): void
    {
        $result = TaxValidationService::validateTaxConsistency([
            'taxa_fixa_deposito' => 1.00,
            'taxa_fixa_pix' => -1,
        ]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('saque', $result['errors'][0]);
    }

    /** @test */
    public function sanitize_tax_data_converte_numeros_e_booleanos(): void
    {
        $data = [
            'taxa_fixa_deposito' => '1.50',
            'taxa_fixa_pix' => 0.90,
            'taxas_personalizadas_ativas' => '1',
            'comissao_afiliado_personalizada' => 'true',
        ];

        $sanitized = TaxValidationService::sanitizeTaxData($data);

        $this->assertIsFloat($sanitized['taxa_fixa_deposito']);
        $this->assertEquals(1.50, $sanitized['taxa_fixa_deposito']);
        $this->assertEquals(0.90, $sanitized['taxa_fixa_pix']);
        $this->assertTrue($sanitized['taxas_personalizadas_ativas']);
        $this->assertTrue($sanitized['comissao_afiliado_personalizada']);
    }

    /** @test */
    public function sanitize_tax_data_converte_string_vazia_em_null_para_numericos(): void
    {
        $data = ['taxa_fixa_deposito' => ''];

        $sanitized = TaxValidationService::sanitizeTaxData($data);

        $this->assertNull($sanitized['taxa_fixa_deposito']);
    }

    /** @test */
    public function get_global_tax_rules_retorna_regras_esperadas(): void
    {
        $rules = TaxValidationService::getGlobalTaxRules();

        $this->assertArrayHasKey('taxa_fixa_deposito', $rules);
        $this->assertArrayHasKey('taxa_fixa_pix', $rules);
        $this->assertArrayHasKey('taxa_comissao_afiliado_padrao', $rules);
        $this->assertStringContainsString('numeric', $rules['taxa_fixa_deposito']);
    }

    /** @test */
    public function get_individual_tax_rules_inclui_taxas_personalizadas_e_afiliado(): void
    {
        $rules = TaxValidationService::getIndividualTaxRules();

        $this->assertArrayHasKey('taxas_personalizadas_ativas', $rules);
        $this->assertArrayHasKey('taxa_fixa_deposito', $rules);
        $this->assertArrayHasKey('taxa_fixa_pix', $rules);
        $this->assertArrayHasKey('taxa_comissao_afiliado', $rules);
        $this->assertArrayHasKey('comissao_afiliado_personalizada', $rules);
    }
}
