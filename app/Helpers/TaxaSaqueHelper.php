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
 * 4. Split da taxa: custo adquirente (CustoAdquirentePixHelper) → Afiliado (R$ 0,50 se houver) → Coratri (resto)
 * 5. Cliente sempre recebe o valor solicitado; taxa é descontada do saldo
 *
 * EXEMPLOS (mesmo padrão do depósito):
 *
 * Caso 1: Taxa global R$ 1,00, sem afiliado, saque R$ 5,00
 * - Cliente recebe: R$ 5,00
 * - Saldo descontado: R$ 6,00 (5 + 1)
 * - Taxa: R$ 1,00 → Adquirente R$ 0,025 + Coratri R$ 0,975
 *
 * Caso 2: Taxa personalizada R$ 0,90, sem afiliado, saque R$ 5,00
 * - Cliente recebe: R$ 5,00
 * - Saldo descontado: R$ 5,90 (5 + 0,90)
 * - Taxa: R$ 0,90 → Adquirente R$ 0,025 + Coratri R$ 0,875
 *
 * Caso 3: Taxa personalizada R$ 0,90, COM afiliado, saque R$ 5,00
 * - Cliente recebe: R$ 5,00 (taxa NÃO muda com afiliado)
 * - Saldo descontado: R$ 5,90 (5 + 0,90)
 * - Taxa: R$ 0,90 → Adquirente R$ 0,025 + Afiliado R$ 0,50 + Coratri R$ 0,375
 */
class TaxaSaqueHelper
{
    /**
     * Calcula a taxa de saque considerando prioridade do usuário
     *
     * @param  float  $amount  Valor do saque
     * @param  App  $setting  Configurações do sistema
     * @param  User  $user  Usuário específico
     * @param  bool  $isInterfaceWeb  Se é saque via interface web (true) ou API (false)
     * @param  bool  $taxaPorFora  Se true, cliente recebe valor integral e taxa é descontada do saldo
     * @param  string|null  $adquirenteReferencia  Referência do adquirente PIX (para custo fixo via CustoAdquirentePixHelper)
     * @return array [
     *               'taxa_cash_out' => float,          // Taxa total cobrada do cliente
     *               'saque_liquido' => float,          // Valor que o cliente recebe
     *               'descricao' => string,             // Descrição do tipo de taxa
     *               'valor_total_descontar' => float,  // Total a ser descontado do saldo
     *               'taxa_aplicacao' => float,         // Lucro líquido da aplicação (taxa - custo adquirente)
     *               'taxa_adquirente' => float         // Custo do adquirente PIX (Adquirente PIX)
     *               ]
     */
    public static function calcularTaxaSaque($amount, $setting, $user, $isInterfaceWeb = false, $taxaPorFora = false, ?string $adquirenteReferencia = null)
    {
        // Validação de entrada
        if ($amount < 0) {
            throw new \InvalidArgumentException('O valor do saque não pode ser negativo.');
        }

        if (! $setting) {
            throw new \InvalidArgumentException('Configurações do sistema são obrigatórias.');
        }

        if (! $user) {
            throw new \InvalidArgumentException('Usuário é obrigatório para cálculo de taxa de saque.');
        }

        Log::info('=== TAXASAQUEHELPER::calcularTaxaSaque INICIADO ===', [
            'amount' => $amount,
            'user_id' => $user->user_id ?? 'N/A',
            'isInterfaceWeb' => $isInterfaceWeb,
            'taxaPorFora' => $taxaPorFora,
        ]);

        // IMPORTANTE: Recarregar usuário do banco para garantir dados atualizados (evita cache)
        if ($user && isset($user->user_id)) {
            $user = \App\Models\User::where('user_id', $user->user_id)->first();
        }

        // Verificar se o usuário tem taxas personalizadas ativas
        $taxasPersonalizadasAtivas = $user && isset($user->taxas_personalizadas_ativas) && $user->taxas_personalizadas_ativas;

        // Modo de cobrança por PORCENTAGEM (individual, exclusivo da taxa fixa).
        $modoPercentual = $taxasPersonalizadasAtivas
            && isset($user->taxa_modo_percentual) && $user->taxa_modo_percentual;
        $percentualAplicado = 0.0;

        // Obter taxa total cobrada do cliente
        if ($modoPercentual) {
            // Taxa por PORCENTAGEM sobre o valor do saque. Substitui a taxa fixa do usuário.
            $percentualAplicado = max(0, (float) ($user->taxa_percentual_pix ?? 0));
            $taxaPercentualBruta = ($amount * $percentualAplicado) / 100;

            // PISO: nunca menor que o custo fixo da adquirente principal (Treeal).
            $piso = CustoAdquirentePixHelper::pisoTaxa($amount);
            $taxaTotal = max($taxaPercentualBruta, $piso);
            $descricao = $isInterfaceWeb ? 'PERSONALIZADA_INTERFACE_WEB_PERCENTUAL' : 'PERSONALIZADA_API_PERCENTUAL';

            Log::info('TaxaSaqueHelper::calcularTaxaSaque - Usando taxa percentual', [
                'user_id' => $user->user_id ?? 'N/A',
                'percentual' => $percentualAplicado,
                'taxa_percentual_bruta' => $taxaPercentualBruta,
                'piso_taxa' => $piso,
                'taxa_aplicada' => $taxaTotal,
            ]);
        } elseif ($taxasPersonalizadasAtivas) {
            // Usar taxa fixa personalizada do usuário
            $taxaTotal = $user->taxa_fixa_pix ?? $setting->taxa_fixa_pix ?? 1.00;
            $descricao = $isInterfaceWeb ? 'PERSONALIZADA_INTERFACE_WEB_FIXA' : 'PERSONALIZADA_API_FIXA';

            Log::info('TaxaSaqueHelper::calcularTaxaSaque - Usando taxa personalizada', [
                'user_id' => $user->user_id ?? 'N/A',
                'taxa_personalizada' => $user->taxa_fixa_pix ?? 'N/A',
                'taxa_global' => $setting->taxa_fixa_pix ?? 'N/A',
                'taxa_aplicada' => $taxaTotal,
            ]);
        } else {
            // Usar taxa fixa global
            $taxaTotal = $setting->taxa_fixa_pix ?? 1.00;
            $descricao = $isInterfaceWeb ? 'GLOBAL_INTERFACE_WEB_FIXA' : 'GLOBAL_API_FIXA';
        }

        // Garantir que a taxa não seja negativa
        $taxaTotal = max(0, (float) $taxaTotal);

        // IMPORTANTE: A comissão do afiliado NÃO é adicionada à taxa total
        // Ela sai da taxa fixa, reduzindo o lucro da Coratri
        $comissaoAfiliado = 0.00;
        if ($user && $user->affiliate_id) {
            $affiliate = \App\Models\User::where('id', $user->affiliate_id)->first();
            if ($affiliate && $affiliate->comissao_afiliado_personalizada && $affiliate->taxa_comissao_afiliado !== null) {
                $comissaoAfiliado = (float) $affiliate->taxa_comissao_afiliado;
            } else {
                $comissaoAfiliado = (float) ($setting->taxa_comissao_afiliado_padrao ?? 0.50);
            }

            Log::info('TaxaSaqueHelper: Comissão de afiliado (sai da taxa fixa)', [
                'user_id' => $user->user_id,
                'affiliate_id' => $user->affiliate_id,
                'comissao_afiliado' => $comissaoAfiliado,
                'taxa_fixa_usuario' => $taxaTotal,
                'personalizada' => $affiliate && $affiliate->comissao_afiliado_personalizada,
                'nota' => 'A comissão sai da taxa fixa, não é adicionada',
            ]);
        }

        $custoAdquirente = CustoAdquirentePixHelper::custoTransacao($amount, $adquirenteReferencia);

        // Lucro líquido da aplicação = taxa fixa - custo Adquirente PIX - comissão afiliado
        $lucroAplicacao = max(0, $taxaTotal - $custoAdquirente - $comissaoAfiliado);

        Log::info('TaxaSaqueHelper: Taxas calculadas', [
            'user_id' => $user->user_id ?? 'N/A',
            'isInterfaceWeb' => $isInterfaceWeb,
            'taxa_total' => $taxaTotal,
            'comissao_afiliado' => $comissaoAfiliado,
            'custo_adquirente' => $custoAdquirente,
            'lucro_aplicacao' => $lucroAplicacao,
            'descricao' => $descricao,
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
            'taxa_por_fora' => $taxaPorFora,
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
                'custo_adquirente' => $custoAdquirente,
                'lucro_aplicacao' => $lucroAplicacao,
                'valor_total_descontar' => $valor_total_descontar,
                'is_interface_web' => $isInterfaceWeb,
                'taxa_por_fora' => $taxaPorFora,
                'operacao' => 'calcularTaxaSaque',
            ]
        );

        Log::info('=== TAXASAQUEHELPER::calcularTaxaSaque FINALIZADO ===', [
            'user_id' => $user->user_id ?? 'N/A',
            'resultado' => [
                'taxa_cash_out' => $taxa_cash_out,
                'saque_liquido' => $saque_liquido,
                'valor_total_descontar' => $valor_total_descontar,
                'lucro_aplicacao' => $lucroAplicacao,
                'custo_adquirente' => $custoAdquirente,
            ],
        ]);

        return [
            'taxa_cash_out' => $taxa_cash_out,       // Taxa fixa cobrada do cliente (NÃO muda com afiliado)
            'taxa_aplicacao' => $lucroAplicacao,    // Lucro Coratri (taxa - Adquirente PIX - afiliado)
            'taxa_adquirente' => $custoAdquirente,
            'comissao_afiliado' => $comissaoAfiliado, // Comissão do pai afiliado (R$ 0,50 se houver)
            'saque_liquido' => $saque_liquido,       // Valor que o cliente recebe (sempre o valor solicitado)
            'descricao' => $descricao,
            'valor_total_descontar' => $valor_total_descontar, // Total descontado do saldo (amount + taxa)
            'modo_percentual' => $modoPercentual,
            'taxa_percentual' => $percentualAplicado,
        ];
    }

    /**
     * Calcula o valor máximo que pode ser sacado considerando o saldo disponível
     *
     * @param  float  $saldoDisponivel  Saldo atual do usuário
     * @param  App  $setting  Configurações do sistema
     * @param  User  $user  Usuário específico
     * @param  bool  $isInterfaceWeb  Se é saque via interface web (true) ou API (false)
     * @return array ['valor_maximo' => float, 'taxa_total' => float, 'saldo_restante' => float]
     */
    public static function calcularValorMaximoSaque($saldoDisponivel, $setting, $user, $isInterfaceWeb = false)
    {
        // Verificar se o usuário tem taxas personalizadas ativas
        $taxasPersonalizadasAtivas = $user && isset($user->taxas_personalizadas_ativas) && $user->taxas_personalizadas_ativas;
        $modoPercentual = $taxasPersonalizadasAtivas
            && isset($user->taxa_modo_percentual) && $user->taxa_modo_percentual;

        if ($modoPercentual) {
            // No modo percentual: saldo = valor + valor × taxaEfetiva/100.
            // taxaEfetiva = max(% usuário, % mínimo da adquirente principal).
            $percentual = max(0, (float) ($user->taxa_percentual_pix ?? 0));
            $percentualMinimo = CustoAdquirentePixHelper::percentualPrincipal();
            $taxaEfetivaPercent = max($percentual, $percentualMinimo);

            $valorMaximo = $taxaEfetivaPercent > 0
                ? $saldoDisponivel / (1 + ($taxaEfetivaPercent / 100))
                : $saldoDisponivel;

            $taxaTotal = ($valorMaximo * $taxaEfetivaPercent) / 100;
            $valorMaximo = max(0, $valorMaximo);
            $saldoRestante = $saldoDisponivel - $valorMaximo - $taxaTotal;

            return [
                'valor_maximo' => $valorMaximo,
                'taxa_total' => $taxaTotal,
                'saldo_restante' => $saldoRestante,
            ];
        }

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
            'saldo_restante' => $saldoRestante,
        ];
    }
}
