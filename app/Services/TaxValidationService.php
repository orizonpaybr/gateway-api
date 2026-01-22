<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;

/**
 * Serviço centralizado para validação de taxas globais e individuais
 * Garante consistência em toda a aplicação
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
            // Taxas de Depósito
            'taxa_percentual_deposito' => 'nullable|numeric|min:0|max:100',
            'taxa_fixa_deposito' => 'nullable|numeric|min:0',
            'valor_minimo_deposito' => 'nullable|numeric|min:0',
            
            // Taxas de Saque PIX
            'taxa_percentual_pix' => 'nullable|numeric|min:0|max:100',
            'taxa_minima_pix' => 'nullable|numeric|min:0',
            'taxa_fixa_pix' => 'nullable|numeric|min:0',
            'valor_minimo_saque' => 'nullable|numeric|min:0',
            'limite_mensal_pf' => 'nullable|numeric|min:0',
            'taxa_saque_api' => 'nullable|numeric|min:0|max:100',
            'taxa_saque_crypto' => 'nullable|numeric|min:0|max:100',
            
            // Sistema de Taxas Flexível
            'sistema_flexivel_ativo' => 'nullable|boolean',
            'valor_minimo_flexivel' => 'nullable|numeric|min:0',
            'taxa_fixa_baixos' => 'nullable|numeric|min:0',
            'taxa_percentual_altos' => 'nullable|numeric|min:0|max:100',
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
            
            // Taxas de Depósito Personalizadas
            'taxa_percentual_deposito' => 'nullable|numeric|min:0|max:100',
            'taxa_fixa_deposito' => 'nullable|numeric|min:0',
            'valor_minimo_deposito' => 'nullable|numeric|min:0',
            
            // Taxas de Saque PIX Personalizadas
            'taxa_percentual_pix' => 'nullable|numeric|min:0|max:100',
            'taxa_minima_pix' => 'nullable|numeric|min:0',
            'taxa_fixa_pix' => 'nullable|numeric|min:0',
            'valor_minimo_saque' => 'nullable|numeric|min:0',
            'limite_mensal_pf' => 'nullable|numeric|min:0',
            'taxa_saque_api' => 'nullable|numeric|min:0|max:100',
            'taxa_saque_crypto' => 'nullable|numeric|min:0|max:100',
            
            // Sistema Flexível Personalizado
            'sistema_flexivel_ativo' => 'nullable|boolean',
            'valor_minimo_flexivel' => 'nullable|numeric|min:0',
            'taxa_fixa_baixos' => 'nullable|numeric|min:0',
            'taxa_percentual_altos' => 'nullable|numeric|min:0|max:100',
            
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

        // Validar sistema flexível
        if (isset($data['sistema_flexivel_ativo']) && $data['sistema_flexivel_ativo']) {
            if (empty($data['valor_minimo_flexivel']) || $data['valor_minimo_flexivel'] <= 0) {
                $errors[] = 'Quando o sistema flexível está ativo, o valor mínimo flexível deve ser maior que zero.';
            }
            
            if (empty($data['taxa_fixa_baixos']) && empty($data['taxa_percentual_altos'])) {
                $errors[] = 'Quando o sistema flexível está ativo, pelo menos uma taxa (fixa para baixos ou percentual para altos) deve ser configurada.';
            }
        }

        // Validar taxas de depósito
        if (isset($data['taxa_percentual_deposito']) && $data['taxa_percentual_deposito'] > 100) {
            $errors[] = 'A taxa percentual de depósito não pode ser maior que 100%.';
        }

        // Validar taxas de saque
        if (isset($data['taxa_percentual_pix']) && $data['taxa_percentual_pix'] > 100) {
            $errors[] = 'A taxa percentual de saque PIX não pode ser maior que 100%.';
        }

        if (isset($data['taxa_saque_api']) && $data['taxa_saque_api'] > 100) {
            $errors[] = 'A taxa de saque API não pode ser maior que 100%.';
        }

        if (isset($data['taxa_saque_crypto']) && $data['taxa_saque_crypto'] > 100) {
            $errors[] = 'A taxa de saque cripto não pode ser maior que 100%.';
        }

        // Validar valores mínimos
        if (isset($data['valor_minimo_deposito']) && $data['valor_minimo_deposito'] < 0) {
            $errors[] = 'O valor mínimo de depósito não pode ser negativo.';
        }

        if (isset($data['valor_minimo_saque']) && $data['valor_minimo_saque'] < 0) {
            $errors[] = 'O valor mínimo de saque não pode ser negativo.';
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
            'taxa_percentual_deposito',
            'taxa_fixa_deposito',
            'valor_minimo_deposito',
            'taxa_percentual_pix',
            'taxa_minima_pix',
            'taxa_fixa_pix',
            'valor_minimo_saque',
            'limite_mensal_pf',
            'taxa_saque_api',
            'taxa_saque_crypto',
            'valor_minimo_flexivel',
            'taxa_fixa_baixos',
            'taxa_percentual_altos',
        ];

        $booleanFields = [
            'taxas_personalizadas_ativas',
            'sistema_flexivel_ativo',
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
