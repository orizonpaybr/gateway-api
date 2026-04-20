<?php

namespace App\Services;

use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Restitui ao usuário o valor debitado do saldo quando um Pix Out não conclui
 * (CANCELLED / FAILED após PROCESSING ou PENDING com débito já aplicado).
 *
 * O débito é feito em SaqueController via BalanceService::decrementCombinedBalance;
 * Helper::calculaSaldoLiquido não recalcula users.saldo — por isso o estorno precisa ser explícito.
 */
class WithdrawalFailureRefundService
{
    /**
     * Credita de volta o mesmo total debitado por BalanceService::decrementCombinedBalance.
     * Preferimos `valor_total_descontado` gravado no débito; se ausente (registros antigos), amount + taxa_cash_out.
     * Chamar dentro da mesma transação DB que atualiza o status do saque, com User lockado se possível.
     */
    public static function creditBackIfApplicable(
        SolicitacoesCashOut $cashOut,
        string $previousStatus,
        string $newStatus,
    ): void {
        if (! in_array($newStatus, ['CANCELLED', 'FAILED'], true)) {
            return;
        }

        if (in_array($previousStatus, ['CANCELLED', 'FAILED', 'COMPLETED', 'REFUNDED'], true)) {
            return;
        }

        // Débito ocorre após sucesso da API de payout (PROCESSING/PENDING pós-API) ou no manual (outro fluxo).
        if (! in_array($previousStatus, ['PROCESSING', 'PENDING'], true)) {
            return;
        }

        $pelaLinha = (float) $cashOut->amount + (float) ($cashOut->taxa_cash_out ?? 0);
        $valorDevolver = $cashOut->valor_total_descontado !== null && (float) $cashOut->valor_total_descontado > 0
            ? (float) $cashOut->valor_total_descontado
            : $pelaLinha;

        if ($valorDevolver <= 0) {
            return;
        }

        $user = User::where('user_id', $cashOut->user_id)->lockForUpdate()->first();
        if (! $user) {
            Log::warning('[WITHDRAWAL_REFUND] Usuário não encontrado para restituir Pix Out', [
                'cash_out_id' => $cashOut->id,
                'user_id' => $cashOut->user_id,
            ]);

            return;
        }

        User::where('id', $user->id)->increment('saldo', $valorDevolver);
        CacheKeyService::forgetAffiliateUser((int) $user->id);

        Log::info('[WITHDRAWAL_REFUND] Saldo restituído após falha/cancelamento do Pix Out', [
            'cash_out_id' => $cashOut->id,
            'id_transaction' => $cashOut->idTransaction,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'valor_devolvido' => $valorDevolver,
            'amount' => $cashOut->amount,
            'taxa_cash_out' => $cashOut->taxa_cash_out,
            'valor_total_descontado' => $cashOut->valor_total_descontado,
        ]);
    }
}
