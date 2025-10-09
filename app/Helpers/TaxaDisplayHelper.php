<?php

namespace App\Helpers;

use App\Models\App;
use App\Models\User;

class TaxaDisplayHelper
{
    /**
     * Calcula as taxas que devem ser exibidas para o usuário
     * Prioridade: Usuário > Global > Padrão
     * 
     * @param mixed $user Usuário logado (User model ou objeto com propriedades)
     * @param mixed $setting Configurações globais (App model ou objeto com propriedades)
     * @return array Taxas formatadas para exibição
     */
    public static function getTaxasParaExibicao($user, $setting)
    {
        // Verificar se o usuário tem taxas personalizadas ativas
        if ($user && isset($user->taxas_personalizadas_ativas) && $user->taxas_personalizadas_ativas) {
            return self::getTaxasPersonalizadas($user, $setting);
        }
        
        // Usar taxas globais
        return self::getTaxasGlobais($setting);
    }
    
    /**
     * Retorna as taxas personalizadas do usuário
     */
    private static function getTaxasPersonalizadas($user, $setting)
    {
        // PRIORIDADE 1: Sistema flexível personalizado (se ativo)
        if ($user->sistema_flexivel_ativo) {
            $taxasEntrada = [
                'tipo' => 'flexivel',
                'valor_baixo' => $user->taxa_fixa_baixos ?? $setting->taxa_flexivel_fixa_baixo ?? 1.00,
                'percentual_alto' => $user->taxa_percentual_altos ?? $setting->taxa_flexivel_percentual_alto ?? 4.00,
                'valor_minimo' => $user->valor_minimo_flexivel ?? $setting->taxa_flexivel_valor_minimo ?? 15.00,
                'taxa_fixa_adicional' => $user->taxa_fixa_deposito ?? 0.00
            ];
        } else {
            // PRIORIDADE 2: Configurações básicas personalizadas (se flexível não ativo)
            $taxasEntrada = [
                'tipo' => 'padrao',
                'percentual' => $user->taxa_percentual_deposito ?? $setting->taxa_cash_in_padrao ?? 4.00,
                'taxa_minima' => $user->valor_minimo_deposito ?? $setting->baseline ?? 5.00,
                'taxa_fixa_adicional' => $user->taxa_fixa_deposito ?? 0.00
            ];
        }
        
        $taxasSaida = [
            'dashboard' => $user->taxa_percentual_pix ?? $setting->taxa_cash_out_padrao ?? 5.00, // Taxa real do dashboard
            'api' => $user->taxa_saque_api ?? $setting->taxa_saque_api_padrao ?? $setting->taxa_cash_out_padrao ?? 5.00,
            'cripto' => $user->taxa_saque_crypto ?? $setting->taxa_saque_cripto_padrao ?? 1.00,
            'taxa_fixa_adicional' => $user->taxa_fixa_pix ?? 0.00,
            'taxa_percentual_pix' => $user->taxa_percentual_pix ?? $setting->taxa_cash_out_padrao ?? 5.00,
            'taxa_minima_pix' => $user->taxa_minima_pix ?? 0.80,
            'valor_minimo_saque' => $user->valor_minimo_saque ?? $setting->saque_minimo ?? 1.00,
            'limite_mensal_pf' => $user->limite_mensal_pf ?? $setting->limite_saque_mensal ?? 50000.00,
        ];
        
        return [
            'entrada' => $taxasEntrada,
            'saida' => $taxasSaida,
            'sistema_flexivel_ativo' => $user->sistema_flexivel_ativo ?? false,
            'fonte' => 'usuario'
        ];
    }
    
    /**
     * Retorna apenas as taxas globais (quando personalizadas estão desativadas)
     */
    private static function getTaxasGlobais($setting)
    {
        // PRIORIDADE 1: Sistema flexível global (se ativo)
        if ($setting->taxa_flexivel_ativa) {
            $taxasEntrada = [
                'tipo' => 'flexivel',
                'valor_baixo' => $setting->taxa_flexivel_fixa_baixo ?? 1.00,
                'percentual_alto' => $setting->taxa_flexivel_percentual_alto ?? 4.00,
                'valor_minimo' => $setting->taxa_flexivel_valor_minimo ?? 15.00,
                'taxa_fixa_adicional' => $setting->taxa_fixa_padrao ?? 0.00
            ];
        } else {
            // PRIORIDADE 2: Configurações básicas globais (se flexível não ativo)
            $taxasEntrada = [
                'tipo' => 'padrao',
                'percentual' => $setting->taxa_cash_in_padrao ?? 4.00,
                'taxa_minima' => $setting->baseline ?? 5.00,
                'taxa_fixa_adicional' => $setting->taxa_fixa_padrao ?? 0.00
            ];
        }
        
        $taxasSaida = [
            'dashboard' => $setting->taxa_cash_out_padrao ?? 5.00, // Taxa real do dashboard
            'api' => $setting->taxa_saque_api_padrao ?? $setting->taxa_cash_out_padrao ?? 5.00,
            'cripto' => $setting->taxa_saque_cripto_padrao ?? 1.00,
            'taxa_fixa_adicional' => 0.00
        ];
        
        return [
            'entrada' => $taxasEntrada,
            'saida' => $taxasSaida,
            'sistema_flexivel_ativo' => $setting->taxa_flexivel_ativa ?? false,
            'fonte' => 'global'
        ];
    }
    
    
    /**
     * Calcula taxas de saída
     */
    private static function getTaxasSaida($user, $setting)
    {
        return [
            'dashboard' => 0.00, // Sempre gratuito
            'api' => $user->taxa_saque_api ?? $user->taxa_cash_out ?? $setting->taxa_saque_api_padrao ?? $setting->taxa_cash_out_padrao ?? 5.00,
            'cripto' => $user->taxa_saque_cripto ?? $setting->taxa_saque_cripto_padrao ?? 1.00,
            'taxa_fixa_adicional' => $user->taxa_cash_out_fixa ?? 0.00
        ];
    }
}
