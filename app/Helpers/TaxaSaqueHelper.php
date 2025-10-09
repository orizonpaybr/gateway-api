<?php

namespace App\Helpers;

use App\Models\App;
use App\Models\User;

class TaxaSaqueHelper
{
    /**
     * Calcula a taxa de saque considerando prioridade do usuário
     * 
     * @param float $amount Valor do saque
     * @param App $setting Configurações do sistema
     * @param User $user Usuário específico
     * @param bool $isInterfaceWeb Se é saque via interface web (true) ou API (false)
     * @param bool $taxaPorFora Se true, cliente recebe valor integral e taxa é descontada do saldo
     * @return array ['taxa_cash_out' => float, 'saque_liquido' => float, 'descricao' => string, 'valor_total_descontar' => float]
     */
    public static function calcularTaxaSaque($amount, $setting, $user, $isInterfaceWeb = false, $taxaPorFora = false)
    {
        \Log::info('=== TAXASAQUEHELPER::calcularTaxaSaque INICIADO ===', [
            'amount' => $amount,
            'user_id' => $user->user_id ?? 'N/A',
            'isInterfaceWeb' => $isInterfaceWeb,
            'taxaPorFora' => $taxaPorFora
        ]);

        // Taxa fixa do usuário (prioridade máxima)
        $taxafixa = $user->taxa_cash_out_fixa ?? 0;
        
        \Log::info('TaxaSaqueHelper: Taxa fixa do usuário', [
            'user_id' => $user->user_id ?? 'N/A',
            'taxa_fixa_usuario' => $taxafixa
        ]);
        
        // Taxa percentual
        $taxaPercentual = 0;
        $descricao = "";

        // Verificar se o usuário tem taxas personalizadas ativas
        if ($user && isset($user->taxas_personalizadas_ativas) && $user->taxas_personalizadas_ativas) {
            // Usar taxas personalizadas do usuário
            if ($isInterfaceWeb) {
                $taxaPercentual = $user->taxa_percentual_pix ?? $setting->taxa_cash_out_padrao ?? 5.00;
                $descricao = "PERSONALIZADA_INTERFACE_WEB";
            } else {
                $taxaPercentual = $user->taxa_saque_api ?? $setting->taxa_saque_api_padrao ?? $setting->taxa_cash_out_padrao ?? 5.00;
                $descricao = "PERSONALIZADA_API";
            }
        } else {
            // Usar taxas globais
            if ($isInterfaceWeb) {
                $taxaPercentual = $setting->taxa_cash_out_padrao ?? 5.00;
                $descricao = "GLOBAL_INTERFACE_WEB";
            } else {
                $taxaPercentual = $setting->taxa_saque_api_padrao ?? $setting->taxa_cash_out_padrao ?? 5.00;
                $descricao = "GLOBAL_API";
            }
        }

        \Log::info('TaxaSaqueHelper: Taxa percentual selecionada', [
            'user_id' => $user->user_id ?? 'N/A',
            'isInterfaceWeb' => $isInterfaceWeb,
            'taxa_percentual' => $taxaPercentual,
            'descricao' => $descricao
        ]);

        // Calcular taxa percentual
        $taxaPercentualValor = ($amount * $taxaPercentual) / 100;
        
        // Taxa mínima e taxa fixa PIX - usar personalizadas se disponíveis
        if ($user && isset($user->taxas_personalizadas_ativas) && $user->taxas_personalizadas_ativas) {
            $taxaMinima = $user->taxa_minima_pix ?? 0; // Não usar fallback global se personalizadas estão ativas
            $taxaFixaPix = $user->taxa_fixa_pix ?? 0; // Não usar fallback global se personalizadas estão ativas
        } else {
            // CORREÇÃO: Usar taxa_minima_pix global em vez do baseline para saques
            $taxaMinima = $setting->taxa_minima_pix ?? 0;
            $taxaFixaPix = $setting->taxa_fixa_pix ?? 0;
        }
        
        \Log::info('TaxaSaqueHelper: Cálculos de taxa', [
            'user_id' => $user->user_id ?? 'N/A',
            'amount' => $amount,
            'taxa_percentual' => $taxaPercentual,
            'taxa_percentual_valor' => $taxaPercentualValor,
            'taxa_minima_baseline' => $taxaMinima,
            'taxa_fixa_pix' => $taxaFixaPix
        ]);
        
        // Taxa principal = maior entre taxa percentual e taxa mínima
        $taxaPrincipal = max($taxaPercentualValor, $taxaMinima);
        
        // Taxa total = taxa principal + taxa fixa PIX (se > 0)
        $taxaTotal = $taxaPrincipal + $taxaFixaPix;
        
        \Log::info('TaxaSaqueHelper: Taxa total calculada', [
            'user_id' => $user->user_id ?? 'N/A',
            'taxa_percentual_valor' => $taxaPercentualValor,
            'taxa_minima' => $taxaMinima,
            'taxa_principal' => $taxaPrincipal,
            'taxa_fixa_pix' => $taxaFixaPix,
            'taxa_total' => $taxaTotal,
            'maior_entre_eles' => $taxaPercentualValor > $taxaMinima ? 'PERCENTUAL' : 'MINIMA'
        ]);
        
        // NOVA LÓGICA: Cliente sempre recebe o valor solicitado, taxa é descontada do saldo
        $saque_liquido = $amount; // Cliente recebe exatamente o que solicitou
        $taxa_cash_out = $taxaTotal; // Taxa total a ser descontada
        $valor_total_descontar = $amount + $taxaTotal; // Total a ser descontado do saldo

        \Log::info('TaxaSaqueHelper: Valores finais calculados', [
            'user_id' => $user->user_id ?? 'N/A',
            'amount_solicitado' => $amount,
            'saque_liquido' => $saque_liquido,
            'taxa_cash_out' => $taxa_cash_out,
            'valor_total_descontar' => $valor_total_descontar,
            'is_interface_web' => $isInterfaceWeb,
            'taxa_por_fora' => $taxaPorFora
        ]);

        // Log da operação para debug
        \App\Helpers\BalanceLogHelper::logBalanceOperation(
            'TAXA_CALCULATION',
            $user,
            $taxaTotal,
            'saldo',
            [
                'amount_solicitado' => $amount,
                'taxa_percentual' => $taxaPercentual,
                'taxa_percentual_valor' => $taxaPercentualValor,
                'taxa_fixa' => $taxafixa,
                'taxa_minima' => $taxaMinima,
                'taxa_principal' => $taxaPrincipal,
                'taxa_fixa_pix' => $taxaFixaPix,
                'taxa_total' => $taxaTotal,
                'valor_total_descontar' => $valor_total_descontar,
                'is_interface_web' => $isInterfaceWeb,
                'taxa_por_fora' => $taxaPorFora,
                'operacao' => 'calcularTaxaSaque'
            ]
        );

        \Log::info('=== TAXASAQUEHELPER::calcularTaxaSaque FINALIZADO ===', [
            'user_id' => $user->user_id ?? 'N/A',
            'resultado' => [
                'taxa_cash_out' => $taxa_cash_out,
                'saque_liquido' => $saque_liquido,
                'valor_total_descontar' => $valor_total_descontar
            ]
        ]);

        return [
            'taxa_cash_out' => $taxa_cash_out,
            'saque_liquido' => $saque_liquido,
            'descricao' => $descricao,
            'valor_total_descontar' => $valor_total_descontar
        ];
    }

    /**
     * Calcula o valor máximo que pode ser sacado considerando o saldo disponível
     * 
     * @param float $saldoDisponivel Saldo atual do usuário
     * @param App $setting Configurações do sistema
     * @param User $user Usuário específico
     * @param bool $isInterfaceWeb Se é saque via interface web (true) ou API (false)
     * @return array ['valor_maximo' => float, 'taxa_total' => float, 'saldo_restante' => float]
     */
    public static function calcularValorMaximoSaque($saldoDisponivel, $setting, $user, $isInterfaceWeb = false)
    {
        // Taxa fixa do usuário
        $taxafixa = $user->taxa_cash_out_fixa ?? 0;
        
        // Taxa percentual
        $taxaPercentual = 0;
        
        if ($isInterfaceWeb) {
            $taxaPercentual = $user->taxa_cash_out ?? $setting->taxa_cash_out_padrao ?? 5.00;
        } else {
            $taxaPercentual = $user->taxa_saque_api ?? $setting->taxa_saque_api_padrao ?? $setting->taxa_cash_out_padrao ?? 5.00;
        }
        
        // Taxa mínima - usar taxa_minima_pix em vez do baseline para saques
        $taxaMinima = $setting->taxa_minima_pix ?? 0;
        
        // Taxa fixa PIX
        $taxaFixaPix = $setting->taxa_fixa_pix ?? 0;
        
        // Para calcular o valor máximo, precisamos resolver a equação:
        // saldoDisponivel = valorMaximo + taxaTotal
        // onde taxaTotal = max(valorMaximo * taxaPercentual/100, taxaMinima) + taxaFixa + taxaFixaPix
        
        // Se taxa percentual é 0, então taxaTotal = taxaMinima + taxaFixa + taxaFixaPix
        if ($taxaPercentual == 0) {
            $taxaTotal = $taxaMinima + $taxafixa + $taxaFixaPix;
            $valorMaximo = $saldoDisponivel - $taxaTotal;
        } else {
            // Resolver equação quadrática: saldo = valor + max(valor * taxa/100, taxaMinima) + taxaFixa + taxaFixaPix
            // Se valor * taxa/100 >= taxaMinima: saldo = valor + valor * taxa/100 + taxaFixa + taxaFixaPix
            // Se valor * taxa/100 < taxaMinima: saldo = valor + taxaMinima + taxaFixa + taxaFixaPix
            
            // Caso 1: valor * taxa/100 >= taxaMinima
            // saldo = valor + valor * taxa/100 + taxaFixa + taxaFixaPix
            // saldo = valor * (1 + taxa/100) + taxaFixa + taxaFixaPix
            // valor = (saldo - taxaFixa - taxaFixaPix) / (1 + taxa/100)
            $valorMaximo1 = ($saldoDisponivel - $taxafixa - $taxaFixaPix) / (1 + $taxaPercentual / 100);
            
            // Caso 2: valor * taxa/100 < taxaMinima
            // saldo = valor + taxaMinima + taxaFixa + taxaFixaPix
            // valor = saldo - taxaMinima - taxaFixa - taxaFixaPix
            $valorMaximo2 = $saldoDisponivel - $taxaMinima - $taxafixa - $taxaFixaPix;
            
            // Verificar qual caso se aplica
            if ($valorMaximo1 * $taxaPercentual / 100 >= $taxaMinima) {
                $valorMaximo = $valorMaximo1;
            } else {
                $valorMaximo = $valorMaximo2;
            }
        }
        
        // Garantir que o valor não seja negativo
        $valorMaximo = max(0, $valorMaximo);
        
        // Calcular taxa total para o valor máximo
        $taxaPercentualValor = ($valorMaximo * $taxaPercentual) / 100;
        $taxaTotal = max($taxaPercentualValor, $taxaMinima) + $taxafixa + $taxaFixaPix;
        
        $saldoRestante = $saldoDisponivel - $valorMaximo - $taxaTotal;
        
        return [
            'valor_maximo' => $valorMaximo,
            'taxa_total' => $taxaTotal,
            'saldo_restante' => $saldoRestante
        ];
    }
}