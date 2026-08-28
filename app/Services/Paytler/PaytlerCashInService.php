<?php

namespace App\Services\Paytler;

use App\Models\Solicitacoes;
use App\Services\PaymentProcessingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Crédito de cash-in Paytler com dedup por PAGAMENTO (txid).
 *
 * A Paytler amarra VÁRIOS QRs/charges ao MESMO pagamento (mesmo txid) — pagar um
 * marca todos como COMPLETED. Sem deduplicar, o mesmo R$ credita o saldo N vezes.
 * Aqui um pagamento (txid) credita NO MÁXIMO um depósito; os charges irmãos são
 * anulados (CANCELLED, sem saldo). Lock nomeado por txid serializa webhook x
 * reconciler para o mesmo pagamento (evita corrida de duplo-crédito).
 */
class PaytlerCashInService
{
    /**
     * @return string 'credited' | 'duplicate' | 'noop'
     */
    public function creditIfNotDuplicate(Solicitacoes $deposit, string $txid, ?string $e2e = null): string
    {
        $txid = trim($txid);
        $lockName = $txid !== '' ? 'paytler_pay:'.$txid : null;

        if ($lockName !== null) {
            DB::selectOne('SELECT GET_LOCK(?, 5) AS l', [$lockName]);
        }

        try {
            $outcome = DB::transaction(function () use ($deposit, $txid, $e2e) {
                $locked = Solicitacoes::where('id', $deposit->id)->lockForUpdate()->first();
                if (! $locked || $locked->status === 'PAID_OUT') {
                    return 'noop';
                }

                // Já existe OUTRO depósito paytler creditado (PAID_OUT) pra este pagamento?
                if ($txid !== '' && Solicitacoes::where('executor_ordem', 'paytler')
                    ->where('provider_payment_id', $txid)
                    ->where('status', 'PAID_OUT')
                    ->where('id', '!=', $locked->id)
                    ->exists()
                ) {
                    $locked->update([
                        'status' => 'CANCELLED',
                        'provider_payment_id' => $txid,
                    ]);
                    Log::warning('[PAYTLER][DEDUP] Charge duplicado do mesmo txid — NÃO creditado', [
                        'deposit_id' => $locked->id,
                        'txid' => $txid,
                    ]);

                    return 'duplicate';
                }

                $update = ['status' => 'PAID_OUT'];
                if ($txid !== '') {
                    $update['provider_payment_id'] = $txid;
                }
                if ($e2e !== null && $e2e !== '') {
                    $update['end_to_end'] = $e2e;
                }
                $locked->update($update);

                return 'credited';
            });
        } finally {
            if ($lockName !== null) {
                DB::selectOne('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }

        if ($outcome === 'credited') {
            $fresh = Solicitacoes::find($deposit->id);
            if ($fresh) {
                try {
                    app(PaymentProcessingService::class)->processPaymentReceived($fresh);
                } catch (\Throwable $e) {
                    Log::error('[PAYTLER][CREDIT] Falha ao creditar depósito', [
                        'deposit_id' => $deposit->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $outcome;
    }
}
