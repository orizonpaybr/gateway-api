<?php

namespace App\Services\Simpay;

use App\Helpers\Helper;
use App\Helpers\WebhookClientMessages;
use App\Jobs\ClientWebhookDispatchJob;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Services\AffiliateCommissionService;
use App\Services\PaymentProcessingService;
use App\Services\WithdrawalFailureRefundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $terminalStatuses = ['COMPLETED', 'FAILED', 'CANCELLED', 'REFUNDED'];
        if (! in_array($newStatus, $terminalStatuses, true)) {
            return false;
        }

        $updated = DB::transaction(function () use ($payout, $newStatus, $e2eToSet) {
            $locked = SolicitacoesCashOut::where('id', $payout->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return false;
            }

            if (in_array($locked->status, ['COMPLETED', 'FAILED', 'CANCELLED', 'REFUNDED'], true)) {
                return false;
            }

            $previousStatus = $locked->status;

            $updateData = ['status' => $newStatus];
            if ($e2eToSet !== null && $e2eToSet !== '') {
                $updateData['end_to_end'] = $e2eToSet;
            }

            $locked->update($updateData);

            WithdrawalFailureRefundService::creditBackIfApplicable(
                $locked->fresh(),
                $previousStatus,
                $newStatus
            );

            if ($newStatus === 'COMPLETED') {
                $childUser = User::where('user_id', $locked->user_id)->lockForUpdate()->first();
                if ($childUser) {
                    app(AffiliateCommissionService::class)->processCashOutCommission(
                        $locked->fresh(),
                        $childUser
                    );
                }
            }

            return true;
        });

        if (! $updated) {
            return false;
        }

        Log::info('[SIMPAY][OUTCOME] Status terminal aplicado', [
            'payout_id' => $payout->id,
            'transaction_id' => $payout->idTransaction,
            'new_status' => $newStatus,
        ]);

        $fresh = SolicitacoesCashOut::find($payout->id);
        if ($fresh === null) {
            return true;
        }

        Helper::calculaSaldoLiquido($fresh->user_id);
        app(PaymentProcessingService::class)->invalidateCachesAfterPayment($fresh->user_id);

        $this->dispatchCashOutClientWebhook($fresh, $newStatus, $rawForClientMessage, $paidAtIso);

        return true;
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

    /**
     * @param  array<string, mixed>|null  $rawForClientMessage
     */
    private function dispatchCashOutClientWebhook(
        SolicitacoesCashOut $record,
        string $status,
        ?array $rawForClientMessage,
        ?string $paidAtIso,
    ): void {
        if (empty($record->callback) || $record->callback === 'web') {
            return;
        }

        $record->refresh();

        $payloadForReason = $this->normalizeRawForPixMessage($rawForClientMessage);
        $message = WebhookClientMessages::getMessageForStatus($status, 'PIX_OUT', $payloadForReason === [] ? null : $payloadForReason);

        ClientWebhookDispatchJob::send(
            $record->callback,
            $record->idTransaction,
            $status,
            (float) $record->amount,
            $paidAtIso ?? now()->toIso8601String(),
            ClientWebhookPayloadBuilder::extraForCashOut($record),
            $message
        );
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<string, mixed>
     */
    private function normalizeRawForPixMessage(?array $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        $out = [];

        if (! empty($raw['erro_descriptor'])) {
            $out['message'] = is_string($raw['erro_descriptor'])
                ? $raw['erro_descriptor']
                : (string) $raw['erro_descriptor'];
        }

        foreach (['errorCode', 'rejectionReason', 'code', 'message'] as $k) {
            if (! isset($raw[$k])) {
                continue;
            }
            $v = $raw[$k];
            if (is_string($v) || is_numeric($v)) {
                $out[$k] = is_string($v) ? $v : (string) $v;
            }
        }

        return $out;
    }
}
