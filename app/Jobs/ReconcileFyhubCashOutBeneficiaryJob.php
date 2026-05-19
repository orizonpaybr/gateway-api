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
 * Envia o postback COMPLETED ao integrador quando a FyHub já tiver creditorAccount no GET.
 * O postback inicial é adiado (não manda só pixKey) — uma consulta por tentativa na fila.
 */
class ReconcileFyhubCashOutBeneficiaryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [5, 8, 12, 15, 20];

    public int $timeout = 45;

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
            FyhubCashOutBeneficiaryEnricher::ASYNC_API_ATTEMPTS,
            FyhubCashOutBeneficiaryEnricher::ASYNC_API_SLEEP_MICROSECONDS,
        );
        $payout->refresh();

        if (trim((string) $payout->beneficiaryname) === '') {
            Log::warning('[FYHUB][BENEFICIARY] Job: recebedor ainda indisponível, nova tentativa agendada', [
                'payout_id' => $payout->id,
                'transaction_id' => $payout->idTransaction,
                'attempt' => $this->attempts(),
            ]);

            throw new \RuntimeException('FyHub creditorAccount ainda não disponível.');
        }

        app(CashOutOutcomeApplier::class)->notifyClientTerminalStatus($payout, $raw);

        Log::info('[FYHUB][BENEFICIARY] Postback enviado com dados do recebedor', [
            'payout_id' => $payout->id,
            'transaction_id' => $payout->idTransaction,
            'beneficiaryname' => $payout->beneficiaryname,
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        $payout = SolicitacoesCashOut::find($this->payoutId);
        if ($payout === null || empty($payout->callback) || $payout->callback === 'web') {
            return;
        }

        if (trim((string) $payout->beneficiaryname) !== '') {
            return;
        }

        Log::error('[FYHUB][BENEFICIARY] Tentativas esgotadas; postback parcial (sem nome/documento)', [
            'payout_id' => $payout->id,
            'transaction_id' => $payout->idTransaction,
            'error' => $exception?->getMessage(),
        ]);

        app(CashOutOutcomeApplier::class)->notifyClientTerminalStatus($payout, null, null, true);
    }
}
