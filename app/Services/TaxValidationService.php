<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;

/**
 * Serviço centralizado para validação de taxas globais e individuais
 * Sistema simplificado: apenas taxas fixas em centavos
 */
class TaxValidationService
{
    /**
     * Validar taxas globais
     * 
     * @param array $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    public static function validateGlobalTaxes(array $data): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, self::getGlobalTaxRules());
    }

    /**
     * Validar taxas individuais/personalizadas
     * 
     * @param array $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    public static function validateIndividualTaxes(array $data): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, self::getIndividualTaxRules());
    }

    /**
     * Regras de validação para taxas globais
     * 
     * @return array
     */
    public static function getGlobalTaxRules(): array
    {
        return [
            // Taxas fixas (em centavos)
            'taxa_fixa_deposito' => 'nullable|numeric|min:0',
            'taxa_fixa_pix' => 'nullable|numeric|min:0',
            'taxa_comissao_afiliado_padrao' => 'nullable|numeric|min:0',
        ];
    }

    /**
     * Regras de validação para taxas individuais
     * 
     * @return array
     */
    public static function getIndividualTaxRules(): array
    {
        return [
            'taxas_personalizadas_ativas' => 'nullable|boolean',

            // Modo de cobrança: false = taxa fixa (centavos), true = porcentagem (exclusivos)
            'taxa_modo_percentual' => 'nullable|boolean',

            // Taxas fixas personalizadas (em centavos)
            'taxa_fixa_deposito' => 'nullable|numeric|min:0',
            'taxa_fixa_pix' => 'nullable|numeric|min:0',

            // Taxas percentuais personalizadas (em %, ex.: 2 = 2%)
            'taxa_percentual_deposito' => 'nullable|numeric|min:0|max:100',
            'taxa_percentual_pix' => 'nullable|numeric|min:0|max:100',
            'valor_minimo_saque' => 'nullable|numeric|min:0',

            // Comissão de afiliado personalizada
            'taxa_comissao_afiliado' => 'nullable|numeric|min:0',
            'comissao_afiliado_personalizada' => 'nullable|boolean',

            // Observações
            'observacoes_taxas' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Validar se valores de taxas são consistentes
     * 
     * @param array $data
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validateTaxConsistency(array $data): array
    {
        $errors = [];

        // Validar taxas de depósito
        if (isset($data['taxa_fixa_deposito']) && $data['taxa_fixa_deposito'] < 0) {
            $errors[] = 'A taxa fixa de depósito não pode ser negativa.';
        }

        // Validar taxas de saque
        if (isset($data['taxa_fixa_pix']) && $data['taxa_fixa_pix'] < 0) {
            $errors[] = 'A taxa fixa de saque PIX não pode ser negativa.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Sanitizar valores de taxas (garantir tipos corretos)
     * 
     * @param array $data
     * @return array
     */
    public static function sanitizeTaxData(array $data): array
    {
        $numericFields = [
            'taxa_fixa_deposito',
            'taxa_fixa_pix',
            'taxa_percentual_deposito',
            'taxa_percentual_pix',
            'taxa_comissao_afiliado_padrao',
            'taxa_comissao_afiliado',
        ];

        $booleanFields = [
            'taxas_personalizadas_ativas',
            'taxa_modo_percentual',
            'comissao_afiliado_personalizada',
        ];

        foreach ($numericFields as $field) {
            if (isset($data[$field])) {
                // Converter string vazia para null
                if ($data[$field] === '') {
                    $data[$field] = null;
                } else {
                    // Garantir que é numérico
                    $data[$field] = is_numeric($data[$field]) ? (float) $data[$field] : null;
                }
            }
        }

        foreach ($booleanFields as $field) {
            if (isset($data[$field])) {
                // Converter para boolean
                $data[$field] = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $data;
    }
}
