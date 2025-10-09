<?php

namespace App\Helpers;

use App\Models\App;

class TaxaFlexivelHelper
{
    /**
     * Calcula a taxa de depósito usando o sistema flexível
     * 
     * @param float $amount Valor do depósito
     * @param App $setting Configurações do sistema
     * @param User|null $user Usuário específico (opcional)
     * @return array ['taxa_cash_in' => float, 'deposito_liquido' => float, 'descricao' => string]
     */
    public static function calcularTaxaDeposito($amount, $setting, $user = null)
    {
        // Verificar se as taxas personalizadas estão ativas
        $taxasPersonalizadasAtivas = $user && isset($user->taxas_personalizadas_ativas) && $user->taxas_personalizadas_ativas;
        
        if ($taxasPersonalizadasAtivas) {
            // MODO PERSONALIZADO: Usuário tem taxas personalizadas ativas
            // PRIORIDADE 1: Sistema flexível personalizado (se ativo)
            if ($user->sistema_flexivel_ativo) {
                $valorMinimo = $user->valor_minimo_flexivel ?? $setting->taxa_flexivel_valor_minimo;
                $taxaFixaBaixo = $user->taxa_fixa_baixos ?? $setting->taxa_flexivel_fixa_baixo;
                $taxaPercentualAlto = $user->taxa_percentual_altos ?? $setting->taxa_flexivel_percentual_alto;
                $descricao = "FLEXIVEL_USUARIO";
                
                return self::calcularTaxaFlexivel($amount, $valorMinimo, $taxaFixaBaixo, $taxaPercentualAlto, $descricao);
            } else {
                // PRIORIDADE 2: Configurações básicas personalizadas (se flexível não ativo)
                return self::calcularTaxaBasica($amount, $setting, $user, "PERSONALIZADA");
            }
        } else {
            // MODO GLOBAL: Usuário não tem taxas personalizadas, usar configurações globais
            // PRIORIDADE 1: Sistema flexível global (se ativo)
            if ($setting->taxa_flexivel_ativa) {
                $valorMinimo = $setting->taxa_flexivel_valor_minimo;
                $taxaFixaBaixo = $setting->taxa_flexivel_fixa_baixo;
                $taxaPercentualAlto = $setting->taxa_flexivel_percentual_alto;
                $descricao = "FLEXIVEL_GLOBAL";
                
                return self::calcularTaxaFlexivel($amount, $valorMinimo, $taxaFixaBaixo, $taxaPercentualAlto, $descricao);
            } else {
                // PRIORIDADE 2: Configurações básicas globais (se flexível não ativo)
                return self::calcularTaxaBasica($amount, $setting, null, "GLOBAL");
            }
        }
    }

    /**
     * Calcula a taxa usando o sistema flexível
     * 
     * @param float $amount Valor do depósito
     * @param float $valorMinimo Valor mínimo para aplicar taxa percentual
     * @param float $taxaFixaBaixo Taxa fixa para valores abaixo do mínimo
     * @param float $taxaPercentualAlto Taxa percentual para valores acima do mínimo
     * @param string $descricao Descrição do tipo de cálculo
     * @return array ['taxa_cash_in' => float, 'deposito_liquido' => float, 'descricao' => string]
     */
    private static function calcularTaxaFlexivel($amount, $valorMinimo, $taxaFixaBaixo, $taxaPercentualAlto, $descricao)
    {
        if ($amount < $valorMinimo) {
            // Depósitos abaixo do valor mínimo: taxa fixa
            $taxa_cash_in = $taxaFixaBaixo;
            $deposito_liquido = $amount - $taxa_cash_in;
            $descricao .= "_FIXA";
        } else {
            // Depósitos acima do valor mínimo: taxa percentual
            $taxa_cash_in = ($amount * $taxaPercentualAlto) / 100;
            $deposito_liquido = $amount - $taxa_cash_in;
            $descricao .= "_PERCENTUAL";
        }

        return [
            'taxa_cash_in' => $taxa_cash_in,
            'deposito_liquido' => $deposito_liquido,
            'descricao' => $descricao
        ];
    }

    /**
     * Calcula a taxa usando as configurações básicas (percentual + fixa + mínimo)
     * 
     * @param float $amount Valor do depósito
     * @param App $setting Configurações do sistema
     * @param User|null $user Usuário específico (opcional)
     * @param string $tipo Tipo de configuração (PERSONALIZADA ou GLOBAL)
     * @return array ['taxa_cash_in' => float, 'deposito_liquido' => float, 'descricao' => string]
     */
    private static function calcularTaxaBasica($amount, $setting, $user = null, $tipo = "GLOBAL")
    {
        if ($tipo === "PERSONALIZADA" && $user) {
            // Usar configurações básicas personalizadas do usuário
            $taxaPercentual = $user->taxa_percentual_deposito ?? $setting->taxa_cash_in_padrao ?? 4.00;
            $taxaFixaAdicional = $user->taxa_fixa_deposito ?? 0.00;
            $descricao = "PERSONALIZADA_BASICA";
        } else {
            // Usar configurações básicas globais
            $taxaPercentual = $setting->taxa_cash_in_padrao ?? 4.00;
            $taxaFixaAdicional = $setting->taxa_fixa_padrao ?? 0.00;
            $descricao = "GLOBAL_BASICA";
        }
        
        // Calcular taxa: APENAS percentual + fixa (sem taxa mínima)
        $taxaPercentualCalculada = ($amount * $taxaPercentual) / 100;
        $taxa_cash_in = $taxaPercentualCalculada + $taxaFixaAdicional;
        $deposito_liquido = $amount - $taxa_cash_in;
        $descricao .= "_PERCENTUAL_FIXA";

        return [
            'taxa_cash_in' => $taxa_cash_in,
            'deposito_liquido' => $deposito_liquido,
            'descricao' => $descricao
        ];
    }

    /**
     * Calcula a taxa usando o sistema antigo (baseline)
     * 
     * @param float $amount Valor do depósito
     * @param App $setting Configurações do sistema
     * @param User|null $user Usuário específico (opcional)
     * @return array ['taxa_cash_in' => float, 'deposito_liquido' => float, 'descricao' => string]
     */
    private static function calcularTaxaAntiga($amount, $setting, $user = null)
    {
        // Verificar se o usuário tem taxas personalizadas ativas
        if ($user && isset($user->taxas_personalizadas_ativas) && $user->taxas_personalizadas_ativas) {
            // Usar taxas personalizadas do usuário
            $taxaPercentual = $user->taxa_percentual_deposito ?? $setting->taxa_cash_in_padrao ?? 4.00;
            $taxaFixaAdicional = $user->taxa_fixa_deposito ?? 0.00;
            // CORREÇÃO: Usar valor_minimo_deposito personalizado em vez do baseline global
            $taxaMinima = $user->valor_minimo_deposito ?? $setting->baseline ?? 5.00;
            $descricao = "PERSONALIZADA_PORCENTAGEM";
        } else {
            // Usar taxas globais
            $taxaPercentual = $setting->taxa_cash_in_padrao ?? 4.00;
            $taxaFixaAdicional = 0.00;
            $taxaMinima = $setting->baseline ?? 5.00;
            $descricao = "GLOBAL_PORCENTAGEM";
        }
        
        $taxatotal = ($amount * $taxaPercentual) / 100;
        $deposito_liquido = $amount - $taxatotal - $taxaFixaAdicional;
        $taxa_cash_in = $taxatotal + $taxaFixaAdicional;

        if ($taxatotal < $taxaMinima) {
            $deposito_liquido = $amount - $taxaMinima - $taxaFixaAdicional;
            $taxa_cash_in = $taxaMinima + $taxaFixaAdicional;
            $descricao = str_replace('PORCENTAGEM', 'FIXA', $descricao);
        }

        return [
            'taxa_cash_in' => $taxa_cash_in,
            'deposito_liquido' => $deposito_liquido,
            'descricao' => $descricao
        ];
    }
}
