<?php

namespace App\Services\FluxPayments;

use App\Helpers\Helper;
use App\Models\SolicitacoesCashOut;
use App\Services\CashOut\CashOutOutcomeApplier;
use App\Services\PixAcquirer\PixAcquirerInterface;
use App\Services\PixAcquirer\PixAcquirerManager;
use Illuminate\Support\Facades\Log;

/**
 * Aplica status terminal de cash-out da família A55 (FluxPayments / Paya55)
 * — estorno + webhook — de forma idempotente. O provider vem sempre do próprio
 * payout (adquirente_ref / executor_ordem), nunca de constante.
 */
class FluxPaymentsCashOutOutcomeService
{
    /** Prefixo de log derivado do provider do próprio payout: [FLUXPAYMENTS], [PAYA55], ... */
    private function tagFor(SolicitacoesCashOut $payout): string
    {
        $provider = strtolower(trim((string) ($payout->executor_ordem ?? ''))) ?: 'fluxpayments';

        return '['.strtoupper($provider).']';
    }

    /**
     * Resolve a instância da nominal do payout (não o singleton .env).
     */
    public static function resolveAcquirerForPayout(SolicitacoesCashOut $payout): PixAcquirerInterface
    {
        $nominal = strtolower(trim((string) ($payout->adquirente_ref ?? '')));

        $exec = strtolower(trim((string) ($payout->executor_ordem ?? '')));

        // Legado: manual gravava a nominal em executor_ordem antes do approve.
        if ($nominal === '') {
            $nominal = $exec !== ''
                ? $exec
                : strtolower(trim((string) (Helper::adquirenteDefault($payout->user_id, 'pix') ?: 'fluxpayments')));
        }

        return app(PixAcquirerManager::class)->resolve($nominal !== '' ? $nominal : ($exec ?: 'fluxpayments'));
    }

    /**
     * @param  array<string, mixed>|null  $rawForClientMessage
     */
    public function applyFinalStatusIfNeeded(
        SolicitacoesCashOut $payout,
        string $newStatus,
        ?array $rawForClientMessage = null,
        ?string $e2eToSet = null,
        ?string $paidAtIso = null,
    ): bool {
        return app(CashOutOutcomeApplier::class)->applyTerminalStatusIfNeeded(
            $payout,
            $newStatus,
            $rawForClientMessage,
            $e2eToSet,
            $paidAtIso,
            $this->tagFor($payout).'[OUTCOME]',
        );
    }

    /**
     * @return string|null status interno final aplicado (terminal) ou null
     */
    public function pollApiAndApplyIfTerminal(
        SolicitacoesCashOut $payout,
        int $maxAttempts = 3,
        int $sleepMicroseconds = 400_000,
    ): ?string {
        $flux = self::resolveAcquirerForPayout($payout);
        if (! $flux->isActive()) {
            Log::warning($this->tagFor($payout).'[POLL] Adquirente inativa para payout', [
                'payout_id' => $payout->id,
                'adquirente_ref' => $payout->adquirente_ref,
                'executor_ordem' => $payout->executor_ordem,
            ]);

            return null;
        }

        for ($i = 0; $i < $maxAttempts; $i++) {
            if ($i > 0) {
                usleep($sleepMicroseconds);
            }

            $payout->refresh();
            if (in_array($payout->status, ['COMPLETED', 'FAILED', 'CANCELLED', 'REFUNDED'], true)) {
                return $payout->status;
            }

            $tid = trim((string) $payout->idTransaction);
            $e2e = trim((string) ($payout->end_to_end ?? ''));
            if ($tid === '' && $e2e === '') {
                return null;
            }

            $result = $flux->getPayoutStatus($tid, $e2e !== '' ? $e2e : null);
            if (! ($result['success'] ?? false)) {
                continue;
            }

            $newStatus = $result['status'];
            if (in_array($newStatus, ['PROCESSING', 'PENDING'], true)) {
                continue;
            }

            if (! in_array($newStatus, ['COMPLETED', 'FAILED', 'CANCELLED', 'REFUNDED'], true)) {
                continue;
            }

            $raw = is_array($result['raw'] ?? null) ? $result['raw'] : [];
            $e2eRaw = $raw['endToEndId'] ?? null;
            $e2eRaw = is_string($e2eRaw) && $e2eRaw !== '' ? $e2eRaw : null;
            $paidAt = isset($raw['paidAt']) && is_string($raw['paidAt']) ? $raw['paidAt'] : null;

            $this->applyFinalStatusIfNeeded($payout, $newStatus, $raw, $e2eRaw, $paidAt);
            $payout->refresh();

            return $newStatus;
        }

        return null;
    }
}
