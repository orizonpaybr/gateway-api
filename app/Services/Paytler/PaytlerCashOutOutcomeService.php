<?php

namespace App\Services\Paytler;

use App\Models\SolicitacoesCashOut;
use App\Services\CashOut\CashOutOutcomeApplier;

/**
 * Aplica status terminal de cash-out Paytler (estorno + webhook ao cliente) de forma idempotente.
 * Usado no webhook, no job de reconciliação e no poll síncrono após createPayout.
 * Espelha {@see \App\Services\Simpay\SimpayCashOutOutcomeService}.
 */
final class PaytlerCashOutOutcomeService
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
            '[PAYTLER][OUTCOME]',
        );
    }

    /**
     * Consulta status algumas vezes; se já estiver terminal, aplica o pipeline do webhook.
     *
     * @return string|null status interno final aplicado ou null se seguir pendente/processando
     */
    public function pollApiAndApplyIfTerminal(
        SolicitacoesCashOut $payout,
        int $maxAttempts = 3,
        int $sleepMicroseconds = 400_000,
    ): ?string {
        $paytler = app(PaytlerPixAcquirerService::class);
        if (! $paytler->isActive()) {
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
            $result = $paytler->getPayoutStatus($tid, $e2e !== '' ? $e2e : null);
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
            $e2eNew = $raw['endToEndId'] ?? null;
            $e2eNew = is_string($e2eNew) && $e2eNew !== '' ? $e2eNew : null;

            $this->applyFinalStatusIfNeeded($payout, $newStatus, $raw, $e2eNew, null);
            $payout->refresh();

            return $newStatus;
        }

        return null;
    }
}
