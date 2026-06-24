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
 * 4. Split da taxa: custo adquirente (CustoAdquirentePixHelper) → Afiliado (R$ 0,50 se houver) → Coratri (resto)
 *
 * EXEMPLOS:
 *
 * Caso 1: Taxa global R$ 1,00, sem afiliado, depósito R$ 5,00
 * - Usuário recebe: R$ 4,00
 * - Taxa: R$ 1,00 → Adquirente R$ 0,025 + Coratri R$ 0,975
 *
 * Caso 2: Taxa personalizada R$ 0,90, sem afiliado, depósito R$ 5,00
 * - Usuário recebe: R$ 4,10
 * - Taxa: R$ 0,90 → Adquirente R$ 0,025 + Coratri R$ 0,875
 *
 * Caso 3: Taxa personalizada R$ 0,90, COM afiliado, depósito R$ 5,00
 * - Usuário recebe: R$ 4,10 (taxa NÃO muda com afiliado)
 * - Taxa: R$ 0,90 → Adquirente R$ 0,025 + Afiliado R$ 0,50 + Coratri R$ 0,375
 */
class TaxaFlexivelHelper
{
    /**
     * Calcula a taxa de depósito usando taxa fixa
     *
     * @param  float  $amount  Valor do depósito (valor bruto solicitado pelo cliente)
     * @param  App  $setting  Configurações do sistema
     * @param  User|null  $user  Usuário específico (opcional)
     * @param  string|null  $adquirenteReferencia  Referência do adquirente PIX (para custo fixo via CustoAdquirentePixHelper)
     * @return array [
     *               'taxa_cash_in' => float,           // Taxa total cobrada do cliente (taxa fixa configurada)
     *               'deposito_liquido' => float,       // Valor que o cliente recebe (amount - taxa_cash_in)
     *               'descricao' => string,             // Descrição do tipo de taxa
     *               'taxa_aplicacao' => float,         // Lucro líquido da aplicação (taxa - custo Adquirente PIX)
     *               'taxa_adquirente' => float,        // Custo do adquirente PIX (Adquirente PIX)
     *               'valor_recebido_adquirente' => float   // Valor líquido após custo adquirente (informativo)
     *               ]
     */
    public static function calcularTaxaDeposito($amount, $setting, $user = null, ?string $adquirenteReferencia = null)
    {
        // Validação de entrada
        if ($amount < 0) {
            throw new \InvalidArgumentException('O valor do depósito não pode ser negativo.');
        }

        if (! $setting) {
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

        // Modo de cobrança por PORCENTAGEM (individual, exclusivo da taxa fixa).
        // Só vale quando o usuário tem taxas personalizadas ativas E ativou o modo percentual.
        $modoPercentual = $taxasPersonalizadasAtivas
            && isset($user->taxa_modo_percentual) && $user->taxa_modo_percentual;
        $percentualAplicado = 0.0;

        // Obter taxa total cobrada do cliente
        if ($modoPercentual) {
            // Taxa por PORCENTAGEM sobre o valor (ex.: 2% de R$ 10,00 = R$ 0,20).
            // Substitui a taxa fixa em centavos do usuário.
            $percentualAplicado = max(0, (float) ($user->taxa_percentual_deposito ?? 0));
            $taxaPercentualBruta = ($amount * $percentualAplicado) / 100;

            // PISO: nunca menor que a taxa padrão em centavos da adquirente principal (Treeal).
            $piso = CustoAdquirentePixHelper::pisoCentavos();
            $taxaTotal = max($taxaPercentualBruta, $piso);
            $descricao = 'PERSONALIZADA_PERCENTUAL';

            \Illuminate\Support\Facades\Log::info('TaxaFlexivelHelper::calcularTaxaDeposito - Usando taxa percentual', [
                'user_id' => $user->user_id ?? 'N/A',
                'percentual' => $percentualAplicado,
                'taxa_percentual_bruta' => $taxaPercentualBruta,
                'piso_centavos' => $piso,
                'taxa_aplicada' => $taxaTotal,
                'amount' => $amount,
            ]);
        } elseif ($taxasPersonalizadasAtivas) {
            // Usar taxa fixa personalizada do usuário
            $taxaTotal = (float) ($user->taxa_fixa_deposito ?? $setting->taxa_fixa_padrao ?? 1.00);
            $descricao = 'PERSONALIZADA_FIXA';

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
            $descricao = 'GLOBAL_FIXA';

            \Illuminate\Support\Facades\Log::info('TaxaFlexivelHelper::calcularTaxaDeposito - Usando taxa global', [
                'taxa_global' => $setting->taxa_fixa_padrao ?? 'N/A',
                'taxa_aplicada' => $taxaTotal,
                'amount' => $amount,
            ]);
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

            \Illuminate\Support\Facades\Log::info('TaxaFlexivelHelper: Comissão de afiliado (sai da taxa fixa)', [
                'user_id' => $user->user_id,
                'affiliate_id' => $user->affiliate_id,
                'comissao_afiliado' => $comissaoAfiliado,
                'taxa_fixa_usuario' => $taxaTotal,
                'personalizada' => $affiliate && $affiliate->comissao_afiliado_personalizada,
                'nota' => 'A comissão sai da taxa fixa, não é adicionada',
            ]);
        }

        $custoAdquirente = CustoAdquirentePixHelper::custoFixoTransacao($adquirenteReferencia);

        // Lucro líquido da aplicação = taxa fixa - custo Adquirente PIX - comissão afiliado
        $lucroAplicacao = max(0, $taxaTotal - $custoAdquirente - $comissaoAfiliado);

        // Depósito líquido para o cliente = valor bruto - taxa fixa (NÃO muda com afiliado)
        $depositoLiquido = max(0, $amount - $taxaTotal);

        // Valor líquido após custo Adquirente PIX (informativo)
        $valorRecebidoAdquirente = max(0, $amount - $custoAdquirente);

        return [
            'taxa_cash_in' => $taxaTotal,
            'taxa_aplicacao' => $lucroAplicacao,
            'taxa_adquirente' => $custoAdquirente,
            'comissao_afiliado' => $comissaoAfiliado,
            'deposito_liquido' => $depositoLiquido,
            'valor_recebido_adquirente' => $valorRecebidoAdquirente,
            'descricao' => $descricao,
            'modo_percentual' => $modoPercentual,
            'taxa_percentual' => $percentualAplicado,
        ];
    }
}
