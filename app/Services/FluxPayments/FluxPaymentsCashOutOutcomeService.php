<?php

namespace App\Services\FluxPayments;

use App\Models\SolicitacoesCashOut;
use App\Services\CashOut\CashOutOutcomeApplier;

/**
 * Aplica status terminal de cash-out FluxPayments (estorno + webhook) de forma idempotente.
 */
final class FluxPaymentsCashOutOutcomeService
{
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
            '[FLUXPAYMENTS][OUTCOME]',
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
        $flux = app(FluxPaymentsPixAcquirerService::class);
        if (! $flux->isActive()) {
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
