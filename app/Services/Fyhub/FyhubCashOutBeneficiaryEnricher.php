<?php

namespace App\Services\Fyhub;

use App\Models\SolicitacoesCashOut;
use App\Services\CashOut\CashOutBeneficiaryResolver;
use Illuminate\Support\Facades\Log;

/**
 * Garante nome/documento do recebedor Pix real (DICT) antes do postback ao integrador.
 * A resposta síncrona do POST /pix/payments/dict não traz creditorAccount; a consulta GET sim.
 */
final class FyhubCashOutBeneficiaryEnricher
{
    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<string, mixed>
     */
    public function enrich(SolicitacoesCashOut $payout, ?array $raw = null): array
    {
        $merged = is_array($raw) ? $raw : [];

        if ($payout->executor_ordem !== 'fyhub') {
            return $merged;
        }

        if (CashOutBeneficiaryResolver::resolve($merged) !== []) {
            return $merged;
        }

        $fyhub = app(FyhubPixAcquirerService::class);
        if (! $fyhub->isActive()) {
            return $merged;
        }

        $e2e = trim((string) ($payout->end_to_end ?? ''));
        $tid = trim((string) ($payout->idTransaction ?? ''));

        $result = $fyhub->getPayoutStatus($tid, $e2e !== '' ? $e2e : null);
        if (! ($result['success'] ?? false)) {
            Log::warning('[FYHUB][BENEFICIARY] Não foi possível consultar pagamento para recebedor', [
                'payout_id' => $payout->id,
                'transaction_id' => $tid,
                'message' => $result['message'] ?? null,
            ]);

            return $merged;
        }

        $apiRaw = is_array($result['raw'] ?? null) ? $result['raw'] : [];
        $merged = array_merge($merged, $apiRaw);

        $patch = CashOutBeneficiaryResolver::patchForModel($merged);
        if ($patch !== []) {
            $payout->update($patch);
            Log::info('[FYHUB][BENEFICIARY] Recebedor atualizado na transação', [
                'payout_id' => $payout->id,
                'transaction_id' => $tid,
                'beneficiaryname' => $patch['beneficiaryname'] ?? null,
            ]);
        } else {
            Log::warning('[FYHUB][BENEFICIARY] Consulta sem creditor/receiver utilizável', [
                'payout_id' => $payout->id,
                'transaction_id' => $tid,
                'raw_keys' => array_keys($apiRaw),
            ]);
        }

        return $merged;
    }
}
