<?php

namespace App\Jobs;

use App\Models\SolicitacoesCashOut;
use App\Services\CashOut\CashOutOutcomeApplier;
use App\Services\Fyhub\FyhubCashOutBeneficiaryEnricher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Postback COMPLETED com recebedor — poll agressivo numa execução (afterResponse).
 */
class ReconcileFyhubCashOutBeneficiaryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public int $uniqueFor = 300;

    public function __construct(
        public int $payoutId,
    ) {}

    public function uniqueId(): string
    {
        return 'fyhub-cashout-beneficiary-'.$this->payoutId;
    }

    public function handle(): void
    {
        $payout = SolicitacoesCashOut::find($this->payoutId);
        if ($payout === null) {
            return;
        }

        if ($payout->executor_ordem !== 'fyhub') {
            return;
        }

        if (! CashOutOutcomeApplier::isTerminalStatus((string) $payout->status)) {
            return;
        }

        if (empty($payout->callback) || $payout->callback === 'web') {
            return;
        }

        if (trim((string) $payout->beneficiaryname) !== '') {
            return;
        }

        $enricher = app(FyhubCashOutBeneficiaryEnricher::class);
        $raw = $enricher->enrich(
            $payout,
            null,
            FyhubCashOutBeneficiaryEnricher::JOB_API_ATTEMPTS,
            FyhubCashOutBeneficiaryEnricher::JOB_API_SLEEP_MICROSECONDS,
        );
        $payout->refresh();

        $applier = app(CashOutOutcomeApplier::class);

        if (trim((string) $payout->beneficiaryname) !== '') {
            $applier->notifyClientTerminalStatus($payout, $raw);

            Log::info('[FYHUB][BENEFICIARY] Postback enviado com dados do recebedor', [
                'payout_id' => $payout->id,
                'transaction_id' => $payout->idTransaction,
                'beneficiaryname' => $payout->beneficiaryname,
            ]);

            return;
        }

        Log::warning('[FYHUB][BENEFICIARY] Poll esgotado; postback parcial (sem nome/documento)', [
            'payout_id' => $payout->id,
            'transaction_id' => $payout->idTransaction,
        ]);

        $applier->notifyClientTerminalStatus($payout, $raw, null, true);
    }
}
