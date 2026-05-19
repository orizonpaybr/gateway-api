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

        if ($this->hasBeneficiaryName($merged)) {
            return $merged;
        }

        $fyhub = app(FyhubPixAcquirerService::class);
        if (! $fyhub->isActive()) {
            return $merged;
        }

        $e2e = trim((string) ($payout->end_to_end ?? ''));
        $tid = trim((string) ($payout->idTransaction ?? ''));

        $apiRaw = [];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($attempt > 0) {
                usleep(400_000);
            }

            $result = $fyhub->getPayoutStatus($tid, $e2e !== '' ? $e2e : null);
            if (! ($result['success'] ?? false)) {
                Log::warning('[FYHUB][BENEFICIARY] Não foi possível consultar pagamento para recebedor', [
                    'payout_id' => $payout->id,
                    'transaction_id' => $tid,
                    'attempt' => $attempt + 1,
                    'message' => $result['message'] ?? null,
                ]);

                continue;
            }

            $apiRaw = is_array($result['raw'] ?? null) ? $result['raw'] : [];
            $merged = array_merge($merged, $apiRaw);

            if ($this->hasBeneficiaryName($merged)) {
                break;
            }
        }

        $patch = CashOutBeneficiaryResolver::patchForModel($merged);
        if ($patch !== []) {
            $payout->update($patch);
            Log::info('[FYHUB][BENEFICIARY] Recebedor atualizado na transação', [
                'payout_id' => $payout->id,
                'transaction_id' => $tid,
                'beneficiaryname' => $patch['beneficiaryname'] ?? null,
                'beneficiarydocument' => $patch['beneficiarydocument'] ?? null,
            ]);
        } elseif ($apiRaw !== []) {
            Log::warning('[FYHUB][BENEFICIARY] Consulta sem creditor/receiver utilizável', [
                'payout_id' => $payout->id,
                'transaction_id' => $tid,
                'raw_keys' => array_keys($apiRaw),
            ]);
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $merged
     */
    private function hasBeneficiaryName(array $merged): bool
    {
        $resolved = CashOutBeneficiaryResolver::resolve($merged);

        return isset($resolved['name']) && trim((string) $resolved['name']) !== '';
    }
}
