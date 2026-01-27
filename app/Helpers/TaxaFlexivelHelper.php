<?php

namespace App\Helpers;

use App\Models\App;

/**
 * Helper para cálculo de taxas de depósito
 * Sistema simplificado: apenas taxa fixa em centavos
 * 
 * IMPORTANTE: A TREEAL já desconta automaticamente o custo fixo (2 centavos) quando processa o pagamento.
 * O valor que chega na conta da aplicação já vem líquido (amount - custo TREEAL).
 * 
 * Lógica de taxas:
 * - Taxa total cobrada do cliente = taxa fixa configurada na aplicação (ex: R$ 0,50)
 * - Custo da TREEAL = valor fixo por transação (config treeal.custo_fixo_por_transacao = R$ 0,02)
 * - Valor recebido da TREEAL = amount - custo TREEAL (já descontado automaticamente pela TREEAL)
 * - Depósito líquido para o cliente = amount - taxa total cobrada
 * - Lucro líquido da aplicação = taxa total - custo TREEAL (ex: R$ 0,50 - R$ 0,02 = R$ 0,48)
 */
class TaxaFlexivelHelper
{
    /**
     * Calcula a taxa de depósito usando taxa fixa
     * 
     * @param float $amount Valor do depósito (valor bruto solicitado pelo cliente)
     * @param App $setting Configurações do sistema
     * @param User|null $user Usuário específico (opcional)
     * @return array [
     *   'taxa_cash_in' => float,           // Taxa total cobrada do cliente (taxa fixa configurada)
     *   'deposito_liquido' => float,       // Valor que o cliente recebe (amount - taxa_cash_in)
     *   'descricao' => string,             // Descrição do tipo de taxa
     *   'taxa_aplicacao' => float,         // Lucro líquido da aplicação (taxa - custo TREEAL)
     *   'taxa_adquirente' => float,        // Custo da TREEAL (já descontado automaticamente por ela)
     *   'valor_recebido_treeal' => float   // Valor que a TREEAL envia para nossa conta (amount - custo TREEAL)
     * ]
     */
    public static function calcularTaxaDeposito($amount, $setting, $user = null)
    {
        // Validação de entrada
        if ($amount < 0) {
            throw new \InvalidArgumentException('O valor do depósito não pode ser negativo.');
        }
        
        if (!$setting) {
            throw new \InvalidArgumentException('Configurações do sistema são obrigatórias.');
        }
        
        // IMPORTANTE: Recarregar usuário do banco para garantir dados atualizados (evita cache)
        if ($user && isset($user->user_id)) {
            $user = \App\Models\User::where('user_id', $user->user_id)->first();
        }
        
        // Verificar se as taxas personalizadas estão ativas
        $taxasPersonalizadasAtivas = $user && isset($user->taxas_personalizadas_ativas) && $user->taxas_personalizadas_ativas === true;
        
        \Illuminate\Support\Facades\Log::info('TaxaFlexivelHelper::calcularTaxaDeposito - Verificação de taxas', [
            'user_id' => $user->user_id ?? 'N/A',
            'taxas_personalizadas_ativas' => $taxasPersonalizadasAtivas,
            'taxa_fixa_deposito_usuario' => $user->taxa_fixa_deposito ?? 'N/A',
            'taxa_fixa_padrao_global' => $setting->taxa_fixa_padrao ?? 'N/A',
            'amount' => $amount,
        ]);
        
        // Obter taxa fixa configurada (taxa total cobrada do cliente)
        if ($taxasPersonalizadasAtivas) {
            // Usar taxa fixa personalizada do usuário
            $taxaTotal = (float) ($user->taxa_fixa_deposito ?? $setting->taxa_fixa_padrao ?? 0.00);
            $descricao = "PERSONALIZADA_FIXA";
            
            \Illuminate\Support\Facades\Log::info('TaxaFlexivelHelper::calcularTaxaDeposito - Usando taxa personalizada', [
                'user_id' => $user->user_id ?? 'N/A',
                'taxa_personalizada' => $user->taxa_fixa_deposito ?? 'N/A',
                'taxa_global' => $setting->taxa_fixa_padrao ?? 'N/A',
                'taxa_aplicada' => $taxaTotal,
                'amount' => $amount,
            ]);
        } else {
            // Usar taxa fixa global
            $taxaTotal = (float) ($setting->taxa_fixa_padrao ?? 0.00);
            $descricao = "GLOBAL_FIXA";
            
            \Illuminate\Support\Facades\Log::info('TaxaFlexivelHelper::calcularTaxaDeposito - Usando taxa global', [
                'taxa_global' => $setting->taxa_fixa_padrao ?? 'N/A',
                'taxa_aplicada' => $taxaTotal,
                'amount' => $amount,
            ]);
        }
        
        // Garantir que a taxa não seja negativa
        $taxaTotal = max(0, (float) $taxaTotal);
        
        // Custo fixo da TREEAL por transação (já descontado automaticamente por ela)
        $custoTreeal = (float) config('treeal.custo_fixo_por_transacao');
        
        // Lucro líquido da aplicação = taxa total - custo TREEAL
        // Exemplo: R$ 0,50 (taxa) - R$ 0,02 (custo TREEAL) = R$ 0,48 (lucro)
        // Se a taxa total for menor que o custo da TREEAL, o lucro é zero
        $lucroAplicacao = max(0, $taxaTotal - $custoTreeal);
        
        // Depósito líquido para o cliente = valor bruto - taxa total cobrada
        // Exemplo: R$ 100,00 - R$ 0,50 = R$ 99,50
        $depositoLiquido = max(0, $amount - $taxaTotal);
        
        // Valor que a TREEAL envia para nossa conta (já descontado o custo dela)
        // Exemplo: R$ 100,00 - R$ 0,02 = R$ 99,98
        // NOTA: Este valor é apenas informativo. A TREEAL já desconta automaticamente.
        $valorRecebidoTreeal = max(0, $amount - $custoTreeal);
        
        return [
            'taxa_cash_in' => $taxaTotal,              // Taxa total cobrada do cliente
            'taxa_aplicacao' => $lucroAplicacao,       // Lucro líquido da aplicação
            'taxa_adquirente' => $custoTreeal,         // Custo da TREEAL (já descontado por ela)
            'deposito_liquido' => $depositoLiquido,    // Valor que o cliente recebe
            'valor_recebido_treeal' => $valorRecebidoTreeal, // Valor que a TREEAL envia (informativo)
            'descricao' => $descricao
        ];
    }
}
