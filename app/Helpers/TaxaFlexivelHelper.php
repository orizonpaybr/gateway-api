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
     * @param float $taxaAdquirente Taxa percentual da adquirente (ex: 5.00 para 5%)
     * @return array ['taxa_cash_in' => float, 'deposito_liquido' => float, 'descricao' => string, 'taxa_aplicacao' => float, 'taxa_adquirente' => float]
     */
    public static function calcularTaxaDeposito($amount, $setting, $user = null, $taxaAdquirente = 0.00)
    {
        // Validação de entrada
        if ($amount < 0) {
            throw new \InvalidArgumentException('O valor do depósito não pode ser negativo.');
        }
        
        if (!$setting) {
            throw new \InvalidArgumentException('Configurações do sistema são obrigatórias.');
        }
        
        // Verificar se as taxas personalizadas estão ativas
        $taxasPersonalizadasAtivas = $user && isset($user->taxas_personalizadas_ativas) && $user->taxas_personalizadas_ativas;
        
        // Calcular taxa da aplicação primeiro
        $resultadoAplicacao = null;
        
        if ($taxasPersonalizadasAtivas) {
            // MODO PERSONALIZADO: Usuário tem taxas personalizadas ativas
            // PRIORIDADE 1: Sistema flexível personalizado (se ativo)
            if ($user->sistema_flexivel_ativo) {
                $valorMinimo = $user->valor_minimo_flexivel ?? $setting->taxa_flexivel_valor_minimo;
                $taxaFixaBaixo = $user->taxa_fixa_baixos ?? $setting->taxa_flexivel_fixa_baixo;
                $taxaPercentualAlto = $user->taxa_percentual_altos ?? $setting->taxa_flexivel_percentual_alto;
                $descricao = "FLEXIVEL_USUARIO";
                
                $resultadoAplicacao = self::calcularTaxaFlexivel($amount, $valorMinimo, $taxaFixaBaixo, $taxaPercentualAlto, $descricao);
            } else {
                // PRIORIDADE 2: Configurações básicas personalizadas (se flexível não ativo)
                $resultadoAplicacao = self::calcularTaxaBasica($amount, $setting, $user, "PERSONALIZADA");
            }
        } else {
            // MODO GLOBAL: Usuário não tem taxas personalizadas, usar configurações globais
            // PRIORIDADE 1: Sistema flexível global (se ativo)
            if ($setting->taxa_flexivel_ativa) {
                $valorMinimo = $setting->taxa_flexivel_valor_minimo;
                $taxaFixaBaixo = $setting->taxa_flexivel_fixa_baixo;
                $taxaPercentualAlto = $setting->taxa_flexivel_percentual_alto;
                $descricao = "FLEXIVEL_GLOBAL";
                
                $resultadoAplicacao = self::calcularTaxaFlexivel($amount, $valorMinimo, $taxaFixaBaixo, $taxaPercentualAlto, $descricao);
            } else {
                // PRIORIDADE 2: Configurações básicas globais (se flexível não ativo)
                $resultadoAplicacao = self::calcularTaxaBasica($amount, $setting, null, "GLOBAL");
            }
        }
        
        // Calcular taxa da adquirente (percentual sobre o valor bruto)
        $taxaAdquirente = max(0, min(100, (float) $taxaAdquirente)); // Limitar a 100%
        $taxaAdquirenteValor = ($amount * $taxaAdquirente) / 100;
        
        // Taxa total = taxa aplicação + taxa adquirente
        $taxaTotal = $resultadoAplicacao['taxa_cash_in'] + $taxaAdquirenteValor;
        
        // Depósito líquido = valor bruto - taxa total
        $depositoLiquido = max(0, $amount - $taxaTotal);
        
        return [
            'taxa_cash_in' => $taxaTotal, // Total de todas as taxas
            'taxa_aplicacao' => $resultadoAplicacao['taxa_cash_in'], // Taxa da aplicação apenas
            'taxa_adquirente' => $taxaAdquirenteValor, // Taxa da adquirente apenas
            'deposito_liquido' => $depositoLiquido,
            'descricao' => $resultadoAplicacao['descricao']
        ];
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
        // Validação de valores
        $taxaFixaBaixo = max(0, (float) $taxaFixaBaixo);
        $taxaPercentualAlto = max(0, min(100, (float) $taxaPercentualAlto)); // Limitar a 100%
        $valorMinimo = max(0, (float) $valorMinimo);
        
        if ($amount < $valorMinimo) {
            // Depósitos abaixo do valor mínimo: taxa fixa
            $taxa_cash_in = $taxaFixaBaixo;
            $deposito_liquido = max(0, $amount - $taxa_cash_in); // Garantir que não seja negativo
            $descricao .= "_FIXA";
        } else {
            // Depósitos acima do valor mínimo: taxa percentual
            $taxa_cash_in = ($amount * $taxaPercentualAlto) / 100;
            $deposito_liquido = max(0, $amount - $taxa_cash_in); // Garantir que não seja negativo
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
        
        // Validação e sanitização
        $taxaPercentual = max(0, min(100, (float) $taxaPercentual)); // Limitar a 100%
        $taxaFixaAdicional = max(0, (float) $taxaFixaAdicional);
        
        // Calcular taxa: APENAS percentual + fixa (sem taxa mínima)
        $taxaPercentualCalculada = ($amount * $taxaPercentual) / 100;
        $taxa_cash_in = $taxaPercentualCalculada + $taxaFixaAdicional;
        $deposito_liquido = max(0, $amount - $taxa_cash_in); // Garantir que não seja negativo
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
