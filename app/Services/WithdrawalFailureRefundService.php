<?php

namespace App\Services;

use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Restitui ao usuário o valor debitado do saldo quando um Pix Out não conclui
 * (CANCELLED / FAILED / REFUNDED após PROCESSING/PENDING com débito aplicado).
 *
 * Regras: não estorna PENDING sem debito_*; após estornar zera debito_* (0);
 * user ausente aborta a transação (não deixa FAILED sem estorno).
 */
class WithdrawalFailureRefundService
{
    /**
     * Estorno quando o saque foi marcado COMPLETED cedo demais e o webhook confirmou falha DICT.
     */
    public static function creditBackAfterFalsePositiveCompletion(SolicitacoesCashOut $cashOut): void
    {
        self::creditBackAmount($cashOut, 'COMPLETED', 'FAILED');
    }

    public static function creditBackIfApplicable(
        SolicitacoesCashOut $cashOut,
        string $previousStatus,
        string $newStatus,
    ): void {
        if (! in_array($newStatus, ['CANCELLED', 'FAILED', 'REFUNDED'], true)) {
            return;
        }

        if (in_array($previousStatus, ['CANCELLED', 'FAILED', 'REFUNDED'], true)) {
            return;
        }

        // COMPLETED→FAILED usa creditBackAfterFalsePositiveCompletion; COMPLETED→REFUNDED estorna aqui.
        if ($previousStatus === 'COMPLETED') {
            if ($newStatus !== 'REFUNDED') {
                return;
            }
        } elseif (! in_array($previousStatus, ['PROCESSING', 'PENDING'], true)) {
            return;
        }

        self::creditBackAmount($cashOut, $previousStatus, $newStatus);
    }

    public static function hasRecordedDebit(SolicitacoesCashOut $cashOut): bool
    {
        return (float) ($cashOut->debito_saldo_principal ?? 0) > 0
            || (float) ($cashOut->debito_saldo_afiliado ?? 0) > 0;
    }

    /** Débito já restituído (marcadores gravados como 0). */
    public static function debitAlreadyCleared(SolicitacoesCashOut $cashOut): bool
    {
        return $cashOut->debito_saldo_principal !== null
            && $cashOut->debito_saldo_afiliado !== null
            && ! self::hasRecordedDebit($cashOut);
    }

    /**
     * Marca débito como já restituído (0 = estornado; null = nunca debitou / legado).
     */
    public static function clearDebitMarkers(SolicitacoesCashOut $cashOut): void
    {
        $cashOut->update([
            'debito_saldo_principal' => 0,
            'debito_saldo_afiliado' => 0,
        ]);
    }

    private static function creditBackAmount(
        SolicitacoesCashOut $cashOut,
        string $previousStatus,
        string $newStatus,
    ): void {
        // Já estornado nesta linha — não creditar de novo.
        if (self::debitAlreadyCleared($cashOut)) {
            Log::info('[WITHDRAWAL_REFUND] Débito já limpo — skip', [
                'cash_out_id' => $cashOut->id,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
            ]);

            return;
        }

        // PENDING sem marcador de débito: linha criada antes do decremento (race) — não estornar.
        $markersNull = $cashOut->debito_saldo_principal === null
            && $cashOut->debito_saldo_afiliado === null;
        if ($markersNull && $previousStatus === 'PENDING') {
            Log::info('[WITHDRAWAL_REFUND] PENDING sem débito gravado — nada a estornar', [
                'cash_out_id' => $cashOut->id,
                'new_status' => $newStatus,
            ]);

            return;
        }

        // Sem débito positivo e não é legado PROCESSING (marcadores null): nada a fazer.
        if (! self::hasRecordedDebit($cashOut) && ! $markersNull) {
            return;
        }

        $pelaLinha = (float) $cashOut->amount + (float) ($cashOut->taxa_cash_out ?? 0);
        $valorDevolver = $cashOut->valor_total_descontado !== null && (float) $cashOut->valor_total_descontado > 0
            ? (float) $cashOut->valor_total_descontado
            : $pelaLinha;

        if ($valorDevolver <= 0) {
            return;
        }

        $user = self::resolveUserForCashOut($cashOut);
        if (! $user) {
            // Abortar a transação do applier: status não pode ficar terminal sem estorno.
            throw new RuntimeException(
                '[WITHDRAWAL_REFUND] Usuário não encontrado para restituir Pix Out #'.$cashOut->id
                .' (user_id='.$cashOut->user_id.')'
            );
        }

        $balanceService = app(BalanceService::class);

        $debAf = $cashOut->debito_saldo_afiliado;
        $debPr = $cashOut->debito_saldo_principal;

        if ($debAf !== null && $debPr !== null
            && ((float) $debAf > 0 || (float) $debPr > 0)) {
            $a = round((float) $debAf, 4);
            $p = round((float) $debPr, 4);
            if (abs(($a + $p) - round($valorDevolver, 4)) > 0.02) {
                Log::warning('[WITHDRAWAL_REFUND] Split gravado não bate com valor_total_descontado; usando estorno só em saldo principal', [
                    'cash_out_id' => $cashOut->id,
                    'split_sum' => $a + $p,
                    'valor_devolver' => $valorDevolver,
                ]);
                User::where('id', $user->id)->increment('saldo', $valorDevolver);
            } else {
                $balanceService->incrementCombinedBalanceMirror($user, $a, $p, [
                    'reason' => 'withdrawal_refund',
                    'source' => 'WithdrawalFailureRefundService',
                    'ref_type' => 'solicitacoes_cash_out',
                    'ref_id' => $cashOut->id,
                ]);
            }
        } else {
            $before = (float) $user->saldo;
            User::where('id', $user->id)->increment('saldo', $valorDevolver);
            $user = $user->fresh();
            BalanceLedgerService::record($user, 'saldo', $valorDevolver, $before, (float) $user->saldo, [
                'reason' => 'withdrawal_refund',
                'source' => 'WithdrawalFailureRefundService',
                'ref_type' => 'solicitacoes_cash_out',
                'ref_id' => $cashOut->id,
            ]);
        }

        self::clearDebitMarkers($cashOut);

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
            'debito_saldo_afiliado' => $debAf,
            'debito_saldo_principal' => $debPr,
        ]);

        // COMPLETED→FAILED (falso positivo) reverte comissão no applier; COMPLETED→REFUNDED reverte aqui.
        if ($previousStatus !== 'COMPLETED' || $newStatus === 'REFUNDED') {
            try {
                app(AffiliateCommissionService::class)->reverseCashOutCommissionForFailedWithdrawal($cashOut);
            } catch (\Throwable $e) {
                Log::error('[WITHDRAWAL_REFUND] Estorno ao usuário ok, mas falhou reversão de comissão afiliado (não deve abortar o estorno)', [
                    'cash_out_id' => $cashOut->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Resolve o dono do saque: solicitacoes_cash_out.user_id pode ser user_id ou username.
     */
    public static function resolveUserForCashOut(SolicitacoesCashOut $cashOut): ?User
    {
        $identifier = trim((string) $cashOut->user_id);
        if ($identifier === '') {
            return null;
        }

        return User::query()
            ->where('user_id', $identifier)
            ->orWhere('username', $identifier)
            ->lockForUpdate()
            ->first();
    }
}
