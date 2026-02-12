<?php

namespace App\Helpers;

use App\Models\App;

/**
 * Helper para cálculo de taxas de depósito
 * Sistema simplificado: apenas taxa fixa em reais
 * 
 * LÓGICA COMPLETA:
 * 1. Taxa global padrão: R$ 1,00 (taxa_fixa_padrao) para todos os usuários
 * 2. Taxa personalizada: pode ser definida por usuário (taxa_fixa_deposito)
 * 3. A taxa NÃO muda se houver afiliado - a comissão sai da taxa fixa
 * 4. Split da taxa: Treeal (R$ 0,02) → Afiliado (R$ 0,50 se houver) → Orizon (resto)
 *
 * EXEMPLOS:
 * 
 * Caso 1: Taxa global R$ 1,00, sem afiliado, depósito R$ 5,00
 * - Usuário recebe: R$ 4,00
 * - Taxa: R$ 1,00 → Treeal R$ 0,02 + Orizon R$ 0,98
 *
 * Caso 2: Taxa personalizada R$ 0,90, sem afiliado, depósito R$ 5,00
 * - Usuário recebe: R$ 4,10
 * - Taxa: R$ 0,90 → Treeal R$ 0,02 + Orizon R$ 0,88
 *
 * Caso 3: Taxa personalizada R$ 0,90, COM afiliado, depósito R$ 5,00
 * - Usuário recebe: R$ 4,10 (taxa NÃO muda com afiliado)
 * - Taxa: R$ 0,90 → Treeal R$ 0,02 + Afiliado R$ 0,50 + Orizon R$ 0,38
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
            $taxaTotal = (float) ($user->taxa_fixa_deposito ?? $setting->taxa_fixa_padrao ?? 1.00);
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
            $taxaTotal = (float) ($setting->taxa_fixa_padrao ?? 1.00);
            $descricao = "GLOBAL_FIXA";
            
            \Illuminate\Support\Facades\Log::info('TaxaFlexivelHelper::calcularTaxaDeposito - Usando taxa global', [
                'taxa_global' => $setting->taxa_fixa_padrao ?? 'N/A',
                'taxa_aplicada' => $taxaTotal,
                'amount' => $amount,
            ]);
        }
        
        // Garantir que a taxa não seja negativa
        $taxaTotal = max(0, (float) $taxaTotal);
        
        // IMPORTANTE: A comissão do afiliado NÃO é adicionada à taxa total
        // Ela sai da taxa fixa, reduzindo o lucro da Orizon
        $comissaoAfiliado = 0.00;
        if ($user && $user->affiliate_id) {
            $comissaoAfiliado = 0.50; // Valor fixo de R$0,50 por transação
            
            \Illuminate\Support\Facades\Log::info('TaxaFlexivelHelper: Comissão de afiliado (sai da taxa fixa)', [
                'user_id' => $user->user_id,
                'affiliate_id' => $user->affiliate_id,
                'comissao_afiliado' => $comissaoAfiliado,
                'taxa_fixa_usuario' => $taxaTotal,
                'nota' => 'A comissão sai da taxa fixa, não é adicionada'
            ]);
        }
        
        // Custo fixo da TREEAL por transação (já descontado automaticamente por ela)
        $custoTreeal = (float) config('treeal.custo_fixo_por_transacao');
        
        // Lucro líquido da aplicação = taxa fixa - custo TREEAL - comissão afiliado
        // A comissão do afiliado e o custo Treeal saem da taxa fixa
        $lucroAplicacao = max(0, $taxaTotal - $custoTreeal - $comissaoAfiliado);
        
        // Depósito líquido para o cliente = valor bruto - taxa fixa (NÃO muda com afiliado)
        // Exemplo: R$ 5,00 - R$ 0,90 = R$ 4,10 (taxa 0,90 independente de ter afiliado)
        $depositoLiquido = max(0, $amount - $taxaTotal);
        
        // Valor que a TREEAL envia para nossa conta (já descontado o custo dela)
        // Exemplo: R$ 100,00 - R$ 0,02 = R$ 99,98
        // NOTA: Este valor é apenas informativo. A TREEAL já desconta automaticamente.
        $valorRecebidoTreeal = max(0, $amount - $custoTreeal);
        
        return [
            'taxa_cash_in' => $taxaTotal,              // Taxa fixa cobrada do cliente (NÃO muda com afiliado)
            'taxa_aplicacao' => $lucroAplicacao,       // Lucro Orizon (taxa - Treeal - afiliado)
            'taxa_adquirente' => $custoTreeal,         // Custo da TREEAL (R$ 0,02)
            'comissao_afiliado' => $comissaoAfiliado,  // Comissão do pai afiliado (R$ 0,50 se houver)
            'deposito_liquido' => $depositoLiquido,    // Valor que o cliente recebe (amount - taxa fixa)
            'valor_recebido_treeal' => $valorRecebidoTreeal, // Valor que a TREEAL envia (informativo)
            'descricao' => $descricao
        ];
    }
}
