<?php

namespace App\Helpers;

use App\Models\App;
use App\Models\User;

class SaqueInterfaceHelper
{
    /**
     * Calcula informações para exibição na interface de saque
     * 
     * @param User $user Usuário
     * @param float $valorSolicitado Valor que o usuário quer sacar
     * @return array
     */
    public static function calcularInformacoesSaque($user, $valorSolicitado = null)
    {
        $setting = App::first();
        
        // Saldo disponível = saldo principal + saldo de afiliados (pode sacar pelo PIX normalmente)
        $saldoTotal = (float) ($user->saldo ?? 0) + (float) ($user->saldo_afiliado ?? 0);
        
        // Calcular valor máximo que pode ser sacado
        $valorMaximo = TaxaSaqueHelper::calcularValorMaximoSaque($saldoTotal, $setting, $user, true);
        
        // Se valor foi solicitado, calcular taxas para esse valor
        $taxasSolicitadas = null;
        if ($valorSolicitado !== null) {
            $taxasSolicitadas = TaxaSaqueHelper::calcularTaxaSaque($valorSolicitado, $setting, $user, true);
        }
        
        return [
            'saldo_disponivel' => $saldoTotal,
            'valor_maximo_saque' => $valorMaximo['valor_maximo'],
            'taxa_total_maxima' => $valorMaximo['taxa_total'],
            'saldo_restante_maximo' => $valorMaximo['saldo_restante'],
            'taxas_solicitadas' => $taxasSolicitadas,
            'pode_sacar' => $valorSolicitado !== null ? $saldoTotal >= $taxasSolicitadas['valor_total_descontar'] : false,
            'valor_restante' => $valorSolicitado !== null ? $saldoTotal - $taxasSolicitadas['valor_total_descontar'] : null
        ];
    }

    /**
     * Valida se o usuário pode sacar o valor solicitado
     * 
     * @param User $user Usuário
     * @param float $valorSolicitado Valor solicitado
     * @return array ['valido' => bool, 'erro' => string|null, 'informacoes' => array]
     */
    public static function validarSaque($user, $valorSolicitado)
    {
        $informacoes = self::calcularInformacoesSaque($user, $valorSolicitado);
        
        if (!$informacoes['pode_sacar']) {
            return [
                'valido' => false,
                'erro' => 'Não foi possível sacar, entre em contato com o suporte.',
                'informacoes' => $informacoes
            ];
        }
        
        if ($valorSolicitado > $informacoes['valor_maximo_saque']) {
            return [
                'valido' => false,
                'erro' => 'Valor solicitado excede o limite permitido.',
                'informacoes' => $informacoes
            ];
        }
        
        return [
            'valido' => true,
            'erro' => null,
            'informacoes' => $informacoes
        ];
    }

    /**
     * Formata informações para exibição na interface
     * 
     * @param array $informacoes Informações calculadas
     * @return array
     */
    public static function formatarParaInterface($informacoes)
    {
        return [
            'saldo_disponivel' => 'R$ ' . number_format($informacoes['saldo_disponivel'], 2, ',', '.'),
            'valor_maximo_saque' => 'R$ ' . number_format($informacoes['valor_maximo_saque'], 2, ',', '.'),
            'taxa_total_maxima' => 'R$ ' . number_format($informacoes['taxa_total_maxima'], 2, ',', '.'),
            'saldo_restante_maximo' => 'R$ ' . number_format($informacoes['saldo_restante_maximo'], 2, ',', '.'),
            'valor_restante' => $informacoes['valor_restante'] !== null ? 'R$ ' . number_format($informacoes['valor_restante'], 2, ',', '.') : null,
            'pode_sacar' => $informacoes['pode_sacar']
        ];
    }
}

