<?php

namespace App\Helpers;

use App\Models\App;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Helper para cálculo de taxas de saque
 * Sistema simplificado: apenas taxa fixa em reais
 * 
 * LÓGICA COMPLETA (mesma do depósito):
 * 1. Taxa global padrão: R$ 1,00 (taxa_fixa_pix) para todos os usuários
 * 2. Taxa personalizada: pode ser definida por usuário (taxa_fixa_pix do user)
 * 3. A taxa NÃO muda se houver afiliado - a comissão sai da taxa fixa
 * 4. Split da taxa: Treeal (R$ 0,02) → Afiliado (R$ 0,50 se houver) → Orizon (resto)
 * 5. Cliente sempre recebe o valor solicitado; taxa é descontada do saldo
 *
 * EXEMPLOS (mesmo padrão do depósito):
 * 
 * Caso 1: Taxa global R$ 1,00, sem afiliado, saque R$ 5,00
 * - Cliente recebe: R$ 5,00
 * - Saldo descontado: R$ 6,00 (5 + 1)
 * - Taxa: R$ 1,00 → Treeal R$ 0,02 + Orizon R$ 0,98
 *
 * Caso 2: Taxa personalizada R$ 0,90, sem afiliado, saque R$ 5,00
 * - Cliente recebe: R$ 5,00
 * - Saldo descontado: R$ 5,90 (5 + 0,90)
 * - Taxa: R$ 0,90 → Treeal R$ 0,02 + Orizon R$ 0,88
 *
 * Caso 3: Taxa personalizada R$ 0,90, COM afiliado, saque R$ 5,00
 * - Cliente recebe: R$ 5,00 (taxa NÃO muda com afiliado)
 * - Saldo descontado: R$ 5,90 (5 + 0,90)
 * - Taxa: R$ 0,90 → Treeal R$ 0,02 + Afiliado R$ 0,50 + Orizon R$ 0,38
 */
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
     * @return array [
     *   'taxa_cash_out' => float,          // Taxa total cobrada do cliente
     *   'saque_liquido' => float,          // Valor que o cliente recebe
     *   'descricao' => string,             // Descrição do tipo de taxa
     *   'valor_total_descontar' => float,  // Total a ser descontado do saldo
     *   'taxa_aplicacao' => float,         // Lucro líquido da aplicação (taxa - custo TREEAL)
     *   'taxa_adquirente' => float         // Custo da TREEAL
     * ]
     */
    public static function calcularTaxaSaque($amount, $setting, $user, $isInterfaceWeb = false, $taxaPorFora = false)
    {
        // Validação de entrada
        if ($amount < 0) {
            throw new \InvalidArgumentException('O valor do saque não pode ser negativo.');
        }
        
        if (!$setting) {
            throw new \InvalidArgumentException('Configurações do sistema são obrigatórias.');
        }
        
        if (!$user) {
            throw new \InvalidArgumentException('Usuário é obrigatório para cálculo de taxa de saque.');
        }
        
        Log::info('=== TAXASAQUEHELPER::calcularTaxaSaque INICIADO ===', [
            'amount' => $amount,
            'user_id' => $user->user_id ?? 'N/A',
            'isInterfaceWeb' => $isInterfaceWeb,
            'taxaPorFora' => $taxaPorFora
        ]);

        // IMPORTANTE: Recarregar usuário do banco para garantir dados atualizados (evita cache)
        if ($user && isset($user->user_id)) {
            $user = \App\Models\User::where('user_id', $user->user_id)->first();
        }
        
        // Verificar se o usuário tem taxas personalizadas ativas
        $taxasPersonalizadasAtivas = $user && isset($user->taxas_personalizadas_ativas) && $user->taxas_personalizadas_ativas;
        
        // Obter taxa fixa configurada (taxa total cobrada do cliente)
        if ($taxasPersonalizadasAtivas) {
            // Usar taxa fixa personalizada do usuário
            $taxaTotal = $user->taxa_fixa_pix ?? $setting->taxa_fixa_pix ?? 1.00;
            $descricao = $isInterfaceWeb ? "PERSONALIZADA_INTERFACE_WEB_FIXA" : "PERSONALIZADA_API_FIXA";
            
            Log::info('TaxaSaqueHelper::calcularTaxaSaque - Usando taxa personalizada', [
                'user_id' => $user->user_id ?? 'N/A',
                'taxa_personalizada' => $user->taxa_fixa_pix ?? 'N/A',
                'taxa_global' => $setting->taxa_fixa_pix ?? 'N/A',
                'taxa_aplicada' => $taxaTotal
            ]);
        } else {
            // Usar taxa fixa global
            $taxaTotal = $setting->taxa_fixa_pix ?? 1.00;
            $descricao = $isInterfaceWeb ? "GLOBAL_INTERFACE_WEB_FIXA" : "GLOBAL_API_FIXA";
        }
        
        // Garantir que a taxa não seja negativa
        $taxaTotal = max(0, (float) $taxaTotal);
        
        // IMPORTANTE: A comissão do afiliado NÃO é adicionada à taxa total
        // Ela sai da taxa fixa, reduzindo o lucro da Orizon
        $comissaoAfiliado = 0.00;
        if ($user && $user->affiliate_id) {
            $comissaoAfiliado = 0.50; // Valor fixo de R$0,50 por transação
            
            Log::info('TaxaSaqueHelper: Comissão de afiliado (sai da taxa fixa)', [
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

        Log::info('TaxaSaqueHelper: Taxas calculadas', [
            'user_id' => $user->user_id ?? 'N/A',
            'isInterfaceWeb' => $isInterfaceWeb,
            'taxa_total' => $taxaTotal,
            'comissao_afiliado' => $comissaoAfiliado,
            'custo_treeal' => $custoTreeal,
            'lucro_aplicacao' => $lucroAplicacao,
            'descricao' => $descricao
        ]);

        // Cliente sempre recebe o valor solicitado, taxa é descontada do saldo
        $saque_liquido = $amount;
        $taxa_cash_out = $taxaTotal;
        $valor_total_descontar = $amount + $taxaTotal;

        Log::info('TaxaSaqueHelper: Valores finais calculados', [
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
                'taxa_total' => $taxaTotal,
                'custo_treeal' => $custoTreeal,
                'lucro_aplicacao' => $lucroAplicacao,
                'valor_total_descontar' => $valor_total_descontar,
                'is_interface_web' => $isInterfaceWeb,
                'taxa_por_fora' => $taxaPorFora,
                'operacao' => 'calcularTaxaSaque'
            ]
        );

        Log::info('=== TAXASAQUEHELPER::calcularTaxaSaque FINALIZADO ===', [
            'user_id' => $user->user_id ?? 'N/A',
            'resultado' => [
                'taxa_cash_out' => $taxa_cash_out,
                'saque_liquido' => $saque_liquido,
                'valor_total_descontar' => $valor_total_descontar,
                'lucro_aplicacao' => $lucroAplicacao,
                'custo_treeal' => $custoTreeal
            ]
        ]);

        return [
            'taxa_cash_out' => $taxa_cash_out,       // Taxa fixa cobrada do cliente (NÃO muda com afiliado)
            'taxa_aplicacao' => $lucroAplicacao,    // Lucro Orizon (taxa - Treeal - afiliado)
            'taxa_adquirente' => $custoTreeal,      // Custo da TREEAL (R$ 0,02)
            'comissao_afiliado' => $comissaoAfiliado, // Comissão do pai afiliado (R$ 0,50 se houver)
            'saque_liquido' => $saque_liquido,       // Valor que o cliente recebe (sempre o valor solicitado)
            'descricao' => $descricao,
            'valor_total_descontar' => $valor_total_descontar // Total descontado do saldo (amount + taxa)
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
        // Verificar se o usuário tem taxas personalizadas ativas
        $taxasPersonalizadasAtivas = $user && isset($user->taxas_personalizadas_ativas) && $user->taxas_personalizadas_ativas;
        
        // Taxa fixa
        if ($taxasPersonalizadasAtivas) {
            $taxaFixa = $user->taxa_fixa_pix ?? $setting->taxa_fixa_pix ?? 1;
        } else {
            $taxaFixa = $setting->taxa_fixa_pix ?? 1;
        }
        
        $taxaFixa = max(0, (float) $taxaFixa);
        
        // Valor máximo = saldo disponível - taxa fixa
        $valorMaximo = max(0, $saldoDisponivel - $taxaFixa);
        
        // Taxa total para o valor máximo é a própria taxa fixa
        $taxaTotal = $taxaFixa;
        
        $saldoRestante = $saldoDisponivel - $valorMaximo - $taxaTotal;
        
        return [
            'valor_maximo' => $valorMaximo,
            'taxa_total' => $taxaTotal,
            'saldo_restante' => $saldoRestante
        ];
    }
}
