<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

/**
 * Trait para validações comuns de usuários
 * Evita duplicação de código entre StoreUserRequest e UpdateUserRequest
 */
trait UserRequestTrait
{
    /**
     * Regras para CPF/CNPJ
     */
    protected function cpfCnpjRules(?int $ignoreUserId = null): array
    {
        $rules = [
            'nullable',
            'string',
            'min:11',
            'max:18',
        ];
        
        if ($ignoreUserId) {
            $rules[] = Rule::unique('users', 'cpf_cnpj')->ignore($ignoreUserId);
        } else {
            $rules[] = Rule::unique('users', 'cpf_cnpj');
        }
        
        return $rules;
    }
    
    /**
     * Regras para email
     */
    protected function emailRules(?int $ignoreUserId = null): array
    {
        $rules = [
            $ignoreUserId ? 'sometimes' : 'required',
            'email',
        ];
        
        if ($ignoreUserId) {
            $rules[] = Rule::unique('users', 'email')->ignore($ignoreUserId);
        } else {
            $rules[] = Rule::unique('users', 'email');
        }
        
        return $rules;
    }
    
    /**
     * Regras para status
     */
    protected function statusRules(): array
    {
        return [
            'nullable',
            'integer',
            Rule::in(\App\Constants\UserStatus::getValidStatuses())
        ];
    }
    
    /**
     * Regras para permissão
     */
    protected function permissionRules(): array
    {
        return [
            'nullable',
            'integer',
            Rule::in(\App\Constants\UserPermission::getValidPermissions())
        ];
    }
    
    /**
     * Regras para dados de endereço
     */
    protected function addressRules(): array
    {
        return [
            'cep' => 'nullable|string|max:10',
            'rua' => 'nullable|string|max:255',
            'estado' => 'nullable|string|size:2',
            'cidade' => 'nullable|string|max:100',
            'bairro' => 'nullable|string|max:100',
            'numero_residencia' => 'nullable|string|max:20',
            'complemento' => 'nullable|string|max:255',
        ];
    }
    
    /**
     * Regras para dados empresariais
     */
    protected function businessRules(): array
    {
        return [
            'nome_fantasia' => 'nullable|string|max:255',
            'razao_social' => 'nullable|string|max:255',
            'media_faturamento' => 'nullable|numeric|min:0',
        ];
    }
    
    /**
     * Regras para taxas personalizadas
     */
    protected function customFeesRules(): array
    {
        return [
            'taxas_personalizadas_ativas' => 'nullable|boolean',
            'taxa_percentual_deposito' => 'nullable|numeric|min:0|max:100',
            'taxa_fixa_deposito' => 'nullable|numeric|min:0',
            'valor_minimo_deposito' => 'nullable|numeric|min:0',
            'taxa_percentual_pix' => 'nullable|numeric|min:0|max:100',
            'taxa_minima_pix' => 'nullable|numeric|min:0',
            'taxa_fixa_pix' => 'nullable|numeric|min:0',
            'valor_minimo_saque' => 'nullable|numeric|min:0',
            'limite_mensal_pf' => 'nullable|numeric|min:0',
            'taxa_saque_api' => 'nullable|numeric|min:0|max:100',
            'taxa_saque_crypto' => 'nullable|numeric|min:0|max:100',
        ];
    }
    
    /**
     * Regras para sistema flexível
     */
    protected function flexibleSystemRules(): array
    {
        return [
            'sistema_flexivel_ativo' => 'nullable|boolean',
            'valor_minimo_flexivel' => 'nullable|numeric|min:0',
            'taxa_fixa_baixos' => 'nullable|numeric|min:0',
            'taxa_percentual_altos' => 'nullable|numeric|min:0|max:100',
            'taxa_flexivel_ativa' => 'nullable|boolean',
            'taxa_flexivel_valor_minimo' => 'nullable|numeric|min:0',
            'taxa_flexivel_fixa_baixo' => 'nullable|numeric|min:0',
            'taxa_flexivel_percentual_alto' => 'nullable|numeric|min:0|max:100',
            'observacoes_taxas' => 'nullable|string|max:1000',
        ];
    }
}

