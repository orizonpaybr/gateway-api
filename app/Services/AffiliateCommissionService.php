<?php

namespace App\Services;

use App\Models\AffiliateCommission;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service para processamento de comissões de afiliados
 * 
 * Processa comissões fixas de R$0,50 por transação (cash-in e cash-out)
 * para o pai afiliado quando o filho realiza uma transação.
 */
class AffiliateCommissionService
{
    private const COMMISSION_VALUE = 0.50; // Valor fixo de R$0,50

    public function __construct(
        private BalanceService $balanceService
    ) {}

    /**
     * Processa comissão de cash-in
     * 
     * Desconta R$0,50 do saldo do filho e credita no saldo do pai
     * 
     * @param Solicitacoes $cashin Transação de depósito
     * @param User $user Usuário filho que gerou a transação
     * @return void
     * @throws \Exception Se processamento falhar
     */
    public function processCashInCommission(Solicitacoes $cashin, User $user): void
    {
        if (!$user->affiliate_id) {
            return; // Usuário não tem pai afiliado
        }

        DB::transaction(function () use ($cashin, $user) {
            // Verificar idempotência - evitar processar comissão duas vezes
            $existingCommission = AffiliateCommission::where('solicitacao_id', $cashin->id)
                ->where('user_id', $user->user_id)
                ->where('transaction_type', 'cash_in')
                ->first();

            if ($existingCommission) {
                Log::info("Comissão de afiliado já processada para cash-in", [
                    'solicitacao_id' => $cashin->id,
                    'user_id' => $user->user_id,
                    'affiliate_id' => $user->affiliate_id,
                ]);
                return;
            }

            // Lock no pai afiliado
            $affiliate = User::where('id', $user->affiliate_id)
                ->lockForUpdate()
                ->first();

            if (!$affiliate) {
                Log::warning("Pai afiliado não encontrado", [
                    'affiliate_id' => $user->affiliate_id,
                    'user_id' => $user->user_id,
                ]);
                return;
            }

            // Lock no filho novamente (garantir dados atualizados)
            $user = User::where('user_id', $user->user_id)
                ->lockForUpdate()
                ->first();

            // Criar registro de comissão
            $commission = AffiliateCommission::create([
                'user_id' => $user->user_id,
                'affiliate_id' => $affiliate->id,
                'transaction_type' => 'cash_in',
                'solicitacao_id' => $cashin->id,
                'solicitacao_cash_out_id' => null,
                'commission_value' => self::COMMISSION_VALUE,
                'transaction_amount' => $cashin->amount,
                'status' => 'pending',
            ]);

            // NOTA IMPORTANTE: A comissão já foi descontada do filho no cálculo do deposito_liquido (TaxaFlexivelHelper)
            // O deposito_liquido já foi calculado como: amount - taxa_aplicacao - comissão_afiliado (R$0,50)
            // Não precisamos descontar novamente aqui, apenas creditar no pai
            $balanceBeforeChild = $user->saldo; // Para log apenas
            $balanceAfterChild = $user->saldo; // Não alteramos o saldo do filho aqui

            // Creditar R$0,50 no saldo_afiliado do pai (separado do saldo principal)
            $balanceBeforeAffiliate = $affiliate->saldo_afiliado;
            $this->balanceService->incrementBalance($affiliate, self::COMMISSION_VALUE, 'saldo_afiliado');
            $balanceAfterAffiliate = $affiliate->fresh()->saldo_afiliado;

            // Atualizar status da comissão para paga
            $commission->update(['status' => 'paid']);

            Log::info("Comissão de afiliado processada com sucesso (cash-in)", [
                'commission_id' => $commission->id,
                'solicitacao_id' => $cashin->id,
                'user_id' => $user->user_id,
                'affiliate_id' => $affiliate->id,
                'commission_value' => self::COMMISSION_VALUE,
                'transaction_amount' => $cashin->amount,
                'deposito_liquido' => $cashin->deposito_liquido,
                'taxa_cash_in' => $cashin->taxa_cash_in,
                'nota' => 'Comissão já foi descontada do filho no cálculo do deposito_liquido (TaxaFlexivelHelper)',
                'child_balance' => $balanceBeforeChild, // Saldo do filho não muda aqui
                'affiliate_balance_before' => $balanceBeforeAffiliate,
                'affiliate_balance_after' => $balanceAfterAffiliate,
            ]);
        });
    }

    /**
     * Processa comissão de cash-out
     * 
     * Desconta R$0,50 adicional do saldo do filho e credita no saldo do pai
     * 
     * @param SolicitacoesCashOut $cashout Transação de saque
     * @param User $user Usuário filho que gerou a transação
     * @return void
     * @throws \Exception Se processamento falhar
     */
    public function processCashOutCommission(SolicitacoesCashOut $cashout, User $user): void
    {
        if (!$user->affiliate_id) {
            return; // Usuário não tem pai afiliado
        }

        DB::transaction(function () use ($cashout, $user) {
            // Verificar idempotência
            $existingCommission = AffiliateCommission::where('solicitacao_cash_out_id', $cashout->id)
                ->where('user_id', $user->user_id)
                ->where('transaction_type', 'cash_out')
                ->first();

            if ($existingCommission) {
                Log::info("Comissão de afiliado já processada para cash-out", [
                    'solicitacao_cash_out_id' => $cashout->id,
                    'user_id' => $user->user_id,
                    'affiliate_id' => $user->affiliate_id,
                ]);
                return;
            }

            // Lock no pai afiliado
            $affiliate = User::where('id', $user->affiliate_id)
                ->lockForUpdate()
                ->first();

            if (!$affiliate) {
                Log::warning("Pai afiliado não encontrado", [
                    'affiliate_id' => $user->affiliate_id,
                    'user_id' => $user->user_id,
                ]);
                return;
            }

            // Lock no filho novamente (garantir dados atualizados)
            $user = User::where('user_id', $user->user_id)
                ->lockForUpdate()
                ->first();

            // Verificar saldo suficiente do filho
            if ($user->saldo < self::COMMISSION_VALUE) {
                Log::warning("Saldo insuficiente para comissão de afiliado (cash-out)", [
                    'user_id' => $user->user_id,
                    'saldo_disponivel' => $user->saldo,
                    'comissao_necessaria' => self::COMMISSION_VALUE,
                ]);
                // Não lançar exceção - apenas logar e não processar comissão
                return;
            }

            // Criar registro de comissão
            $commission = AffiliateCommission::create([
                'user_id' => $user->user_id,
                'affiliate_id' => $affiliate->id,
                'transaction_type' => 'cash_out',
                'solicitacao_id' => null,
                'solicitacao_cash_out_id' => $cashout->id,
                'commission_value' => self::COMMISSION_VALUE,
                'transaction_amount' => $cashout->amount,
                'status' => 'pending',
            ]);

            // NOTA IMPORTANTE: A comissão já foi descontada do filho no cálculo da taxa (TaxaSaqueHelper)
            // O valor_total_descontar já inclui: amount + taxa_aplicacao + comissão_afiliado (R$0,50)
            // Não precisamos descontar novamente aqui, apenas creditar no pai
            $balanceBeforeChild = $user->saldo; // Para log apenas
            $balanceAfterChild = $user->saldo; // Não alteramos o saldo do filho aqui

            // Creditar R$0,50 no saldo_afiliado do pai (separado do saldo principal)
            $balanceBeforeAffiliate = $affiliate->saldo_afiliado;
            $this->balanceService->incrementBalance($affiliate, self::COMMISSION_VALUE, 'saldo_afiliado');
            $balanceAfterAffiliate = $affiliate->fresh()->saldo_afiliado;

            // Atualizar status da comissão para paga
            $commission->update(['status' => 'paid']);

            Log::info("Comissão de afiliado processada com sucesso (cash-out)", [
                'commission_id' => $commission->id,
                'solicitacao_cash_out_id' => $cashout->id,
                'user_id' => $user->user_id,
                'affiliate_id' => $affiliate->id,
                'commission_value' => self::COMMISSION_VALUE,
                'transaction_amount' => $cashout->amount,
                'taxa_cash_out' => $cashout->taxa_cash_out,
                'nota' => 'Comissão já foi descontada do filho no cálculo da taxa (TaxaSaqueHelper)',
                'child_balance' => $balanceBeforeChild, // Saldo do filho não muda aqui
                'affiliate_balance_before' => $balanceBeforeAffiliate,
                'affiliate_balance_after' => $balanceAfterAffiliate,
            ]);
        });
    }
}
