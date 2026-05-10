<?php

namespace App\Services\Simpay;

use App\Models\SolicitacoesCashOut;
use App\Services\CashOut\CashOutOutcomeApplier;

/**
 * Aplica status terminal de cash-out Simpay (estorno + webhook ao cliente) de forma idempotente.
 * Usado no webhook, no job de reconciliação e no poll síncrono após createPayout.
 */
final class SimpayCashOutOutcomeService
{
    /**
     * Transição para COMPLETED / FAILED / CANCELLED / REFUNDED quando o registro ainda não está terminal.
     *
     * @param  array<string, mixed>|null  $rawForClientMessage  Payload do provedor (webhook ou raw do status-cashout) para mensagem ao cliente
     * @return bool true se o status foi atualizado nesta chamada
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
            '[SIMPAY][OUTCOME]',
        );
    }

    /**
     * Consulta status-cashout algumas vezes; se já estiver terminal, aplica o mesmo pipeline do webhook.
     *
     * @return string|null status interno final aplicado (terminal) ou null se seguir pendente/processando
     */
    public function pollApiAndApplyIfTerminal(
        SolicitacoesCashOut $payout,
        int $maxAttempts = 3,
        int $sleepMicroseconds = 400_000,
    ): ?string {
        $simpay = app(SimpayPixAcquirerService::class);
        if (! $simpay->isActive()) {
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

            $result = $simpay->getPayoutStatus($tid);
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
            $e2e = $raw['endToEndId'] ?? null;
            $e2e = is_string($e2e) && $e2e !== '' ? $e2e : null;

            $this->applyFinalStatusIfNeeded($payout, $newStatus, $raw, $e2e, null);
            $payout->refresh();

            return $newStatus;
        }

        return null;
    }
}
