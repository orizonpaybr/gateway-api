<?php

namespace App\Services\Treeal;

use App\Models\SolicitacoesCashOut;
use App\Services\CashOut\CashOutOutcomeApplier;

/**
 * Poll da API Contas TREEAL após createPayout (fallback quando webhook ainda não chegou).
 */
final class TreealCashOutOutcomeService
{
    /**
     * @param  array<string, mixed>  $initialRaw
     */
    public function applySyncTerminalOutcome(
        SolicitacoesCashOut $payout,
        string $status,
        array $initialRaw,
        ?string $e2e,
        string $logTag = '[API_PAYOUT][OUTCOME]',
    ): void {
        app(CashOutOutcomeApplier::class)->applyTerminalStatusIfNeeded(
            $payout,
            $status,
            $initialRaw,
            $e2e,
            null,
            $logTag,
        );
    }

    public function pollApiAndApplyIfTerminal(
        SolicitacoesCashOut $payout,
        int $maxAttempts = 3,
        int $sleepMicroseconds = 400_000,
    ): ?string {
        $treeal = app(TreealPixAcquirerService::class);
        if (! $treeal->isActive()) {
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
            if ($tid === '') {
                return null;
            }

            $e2e = trim((string) ($payout->end_to_end ?? ''));
            $result = $treeal->getPayoutStatus($tid, $e2e !== '' ? $e2e : null);
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
            $e2eFromRaw = $raw['endToEndId'] ?? null;
            $e2eFromRaw = is_string($e2eFromRaw) && $e2eFromRaw !== '' ? $e2eFromRaw : null;

            app(CashOutOutcomeApplier::class)->applyTerminalStatusIfNeeded(
                $payout,
                $newStatus,
                $raw,
                $e2eFromRaw,
                null,
                '[TREEAL][OUTCOME]',
            );
            $payout->refresh();

            return $newStatus;
        }

        return null;
    }
}
