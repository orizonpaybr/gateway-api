<?php

namespace App\Services;

use App\Models\AffiliateCommission;
use App\Models\App;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Services\CacheKeyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service para processamento de comissões de afiliados
 */
class AffiliateCommissionService
{
    public function __construct(
        private BalanceService $balanceService
    ) {}

    private function resolveCommissionValue(User $affiliate): float
    {
        if ($affiliate->comissao_afiliado_personalizada && $affiliate->taxa_comissao_afiliado !== null) {
            return (float) $affiliate->taxa_comissao_afiliado;
        }

        $setting = App::first();
        return (float) ($setting->taxa_comissao_afiliado_padrao ?? 0.50);
    }

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

            $commissionValue = $this->resolveCommissionValue($affiliate);

            // Criar registro de comissão
            $commission = AffiliateCommission::create([
                'user_id' => $user->user_id,
                'affiliate_id' => $affiliate->id,
                'transaction_type' => 'cash_in',
                'solicitacao_id' => $cashin->id,
                'solicitacao_cash_out_id' => null,
                'commission_value' => $commissionValue,
                'transaction_amount' => $cashin->amount,
                'status' => 'pending',
            ]);

            // NOTA IMPORTANTE: A comissão já foi descontada do filho no cálculo do deposito_liquido (TaxaFlexivelHelper)
            // Não precisamos descontar novamente aqui, apenas creditar no pai
            $balanceBeforeChild = $user->saldo; // Para log apenas
            $balanceAfterChild = $user->saldo; // Não alteramos o saldo do filho aqui

            // Creditar comissão no saldo_afiliado do pai (separado do saldo principal)
            $balanceBeforeAffiliate = $affiliate->saldo_afiliado;
            $this->balanceService->incrementBalance($affiliate, $commissionValue, 'saldo_afiliado');
            $balanceAfterAffiliate = $affiliate->fresh()->saldo_afiliado;

            // Atualizar status da comissão para paga
            $commission->update(['status' => 'paid']);

            CacheKeyService::forgetAffiliateUser($affiliate->id);

            Log::info("Comissão de afiliado processada com sucesso (cash-in)", [
                'commission_id' => $commission->id,
                'solicitacao_id' => $cashin->id,
                'user_id' => $user->user_id,
                'affiliate_id' => $affiliate->id,
                'commission_value' => $commissionValue,
                'personalizada' => $affiliate->comissao_afiliado_personalizada,
                'transaction_amount' => $cashin->amount,
                'deposito_liquido' => $cashin->deposito_liquido,
                'taxa_cash_in' => $cashin->taxa_cash_in,
                'nota' => 'Comissão já foi descontada do filho no cálculo do deposito_liquido (TaxaFlexivelHelper)',
                'child_balance' => $balanceBeforeChild,
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

            $commissionValue = $this->resolveCommissionValue($affiliate);

            // A comissão já está embutida no valor_total_descontar / taxa na criação do saque — não debitar de novo aqui.

            // Criar registro de comissão
            $commission = AffiliateCommission::create([
                'user_id' => $user->user_id,
                'affiliate_id' => $affiliate->id,
                'transaction_type' => 'cash_out',
                'solicitacao_id' => null,
                'solicitacao_cash_out_id' => $cashout->id,
                'commission_value' => $commissionValue,
                'transaction_amount' => $cashout->amount,
                'status' => 'pending',
            ]);

            // NOTA IMPORTANTE: A comissão já foi descontada do filho no cálculo da taxa (TaxaSaqueHelper)
            // O valor_total_descontar já inclui: amount + taxa_aplicacao + comissão_afiliado
            // Não precisamos descontar novamente aqui, apenas creditar no pai
            $balanceBeforeChild = $user->saldo; // Para log apenas
            $balanceAfterChild = $user->saldo; // Não alteramos o saldo do filho aqui

            // Creditar comissão no saldo_afiliado do pai (separado do saldo principal)
            $balanceBeforeAffiliate = $affiliate->saldo_afiliado;
            $this->balanceService->incrementBalance($affiliate, $commissionValue, 'saldo_afiliado');
            $balanceAfterAffiliate = $affiliate->fresh()->saldo_afiliado;

            // Atualizar status da comissão para paga
            $commission->update(['status' => 'paid']);

            CacheKeyService::forgetAffiliateUser($affiliate->id);

            Log::info("Comissão de afiliado processada com sucesso (cash-out)", [
                'commission_id' => $commission->id,
                'solicitacao_cash_out_id' => $cashout->id,
                'user_id' => $user->user_id,
                'affiliate_id' => $affiliate->id,
                'commission_value' => $commissionValue,
                'personalizada' => $affiliate->comissao_afiliado_personalizada,
                'transaction_amount' => $cashout->amount,
                'taxa_cash_out' => $cashout->taxa_cash_out,
                'nota' => 'Comissão já foi descontada do filho no cálculo da taxa (TaxaSaqueHelper)',
                'child_balance' => $balanceBeforeChild, // Saldo do filho não muda aqui
                'affiliate_balance_before' => $balanceBeforeAffiliate,
                'affiliate_balance_after' => $balanceAfterAffiliate,
            ]);
        });
    }

    /**
     * Reverte comissão paga ao afiliado quando o cash-out do indicado falha ou é cancelado após ter concluído
     * (ex.: estorno). Idempotente se não houver comissão paga para esse saque.
     */
    public function reverseCashOutCommissionForFailedWithdrawal(SolicitacoesCashOut $cashOut): void
    {
        $runner = function () use ($cashOut) {
            $commission = AffiliateCommission::where('solicitacao_cash_out_id', $cashOut->id)
                ->where('transaction_type', 'cash_out')
                ->where('status', 'paid')
                ->lockForUpdate()
                ->first();

            if (! $commission) {
                return;
            }

            $affiliate = User::where('id', $commission->affiliate_id)->lockForUpdate()->first();
            if (! $affiliate) {
                Log::warning('[AFFILIATE][CASH_OUT_REVERSE] Afiliado não encontrado', [
                    'commission_id' => $commission->id,
                    'affiliate_id' => $commission->affiliate_id,
                ]);

                return;
            }

            $amount = (float) $commission->commission_value;
            if ($amount > 0) {
                User::where('id', $affiliate->id)->decrement('saldo_afiliado', $amount);
            }

            $commission->update(['status' => 'reversed']);

            CacheKeyService::forgetAffiliateUser($affiliate->id);

            Log::info('[AFFILIATE][CASH_OUT_REVERSE] Comissão de cash-out estornada do afiliado', [
                'cash_out_id' => $cashOut->id,
                'commission_id' => $commission->id,
                'affiliate_user_id' => $affiliate->user_id,
                'amount' => $amount,
            ]);
        };

        if (DB::transactionLevel() > 0) {
            $runner();
        } else {
            DB::transaction($runner);
        }
    }

    /**
     * Reverte comissão paga ao afiliado quando o depósito do indicado é estornado (MED/refund).
     */
    public function reverseCashInCommissionForRefundedDeposit(Solicitacoes $cashin): void
    {
        $runner = function () use ($cashin) {
            $commission = AffiliateCommission::where('solicitacao_id', $cashin->id)
                ->where('transaction_type', 'cash_in')
                ->where('status', 'paid')
                ->lockForUpdate()
                ->first();

            if (! $commission) {
                return;
            }

            $affiliate = User::where('id', $commission->affiliate_id)->lockForUpdate()->first();
            if (! $affiliate) {
                Log::warning('[AFFILIATE][CASH_IN_REVERSE] Afiliado não encontrado', [
                    'commission_id' => $commission->id,
                    'affiliate_id' => $commission->affiliate_id,
                ]);

                return;
            }

            $amount = (float) $commission->commission_value;
            if ($amount > 0) {
                User::where('id', $affiliate->id)->decrement('saldo_afiliado', $amount);
            }

            $commission->update(['status' => 'reversed']);

            CacheKeyService::forgetAffiliateUser($affiliate->id);

            Log::info('[AFFILIATE][CASH_IN_REVERSE] Comissão de cash-in estornada do afiliado', [
                'solicitacao_id' => $cashin->id,
                'commission_id' => $commission->id,
                'affiliate_user_id' => $affiliate->user_id,
                'amount' => $amount,
            ]);
        };

        if (DB::transactionLevel() > 0) {
            $runner();
        } else {
            DB::transaction($runner);
        }
    }
}
