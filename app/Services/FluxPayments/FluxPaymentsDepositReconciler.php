<?php

namespace App\Services\FluxPayments;

use App\Helpers\WebhookClientMessages;
use App\Jobs\ClientWebhookDispatchJob;
use App\Models\Solicitacoes;
use App\Services\ClientWebhookPayloadBuilder;
use App\Services\PaymentProcessingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Quando o PIX foi pago na FluxPayments mas o postback não chegou,
 * consulta GET /api/v1/transactions/pix-in e liquida o depósito.
 */
class FluxPaymentsDepositReconciler
{
    public function __construct(
        private readonly FluxPaymentsPixAcquirerService $fluxPayments,
    ) {}

    public function reconcileIfPaid(Solicitacoes $deposit): bool
    {
        if ($deposit->executor_ordem !== 'fluxpayments') {
            return false;
        }

        if (! in_array($deposit->status, ['WAITING_FOR_APPROVAL'], true)) {
            return false;
        }

        $txid = trim((string) ($deposit->idTransaction ?? ''));
        if ($txid === '' || ! $this->fluxPayments->isActive()) {
            return false;
        }

        $result = $this->fluxPayments->getChargeStatus($txid);
        if (! ($result['success'] ?? false)) {
            return false;
        }

        if (($result['status'] ?? '') !== 'PAID_OUT') {
            return false;
        }

        $raw = is_array($result['raw'] ?? null) ? $result['raw'] : [];
        $endToEndId = trim((string) ($raw['endToEndId'] ?? ''));
        $paidAt = $raw['paidAt'] ?? null;

        $updated = DB::transaction(function () use ($deposit, $endToEndId) {
            $locked = Solicitacoes::where('id', $deposit->id)->lockForUpdate()->first();
            if (! $locked || in_array($locked->status, ['PAID_OUT', 'COMPLETED'], true)) {
                return false;
            }

            $updateData = ['status' => 'PAID_OUT'];
            if ($endToEndId !== '') {
                $updateData['end_to_end'] = $endToEndId;
            }

            $locked->update($updateData);

            return true;
        });

        if (! $updated) {
            $fresh = Solicitacoes::find($deposit->id);

            return $fresh && in_array($fresh->status, ['PAID_OUT', 'COMPLETED'], true);
        }

        try {
            app(PaymentProcessingService::class)->processPaymentReceived(Solicitacoes::findOrFail($deposit->id));
        } catch (\Throwable $e) {
            Log::error('[FLUXPAYMENTS][RECONCILE] Falha ao creditar depósito', [
                'deposit_id' => $deposit->id,
                'txid' => $txid,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $fresh = $deposit->fresh();
        $this->dispatchClientWebhook($fresh, is_string($paidAt) ? $paidAt : null);

        Log::info('[FLUXPAYMENTS][RECONCILE] Depósito liquidado via consulta pix-in', [
            'deposit_id' => $deposit->id,
            'txid' => $txid,
            'user_id' => $deposit->user_id,
            'provider_status' => $raw['provider_status'] ?? null,
        ]);

        return true;
    }

    private function dispatchClientWebhook(?Solicitacoes $deposit, ?string $paymentDate): void
    {
        if ($deposit === null || empty($deposit->callback) || $deposit->callback === 'web') {
            return;
        }

        ClientWebhookDispatchJob::send(
            $deposit->callback,
            $deposit->idTransaction,
            'PAID_OUT',
            (float) $deposit->amount,
            is_string($paymentDate) && $paymentDate !== '' ? $paymentDate : now()->toIso8601String(),
            ClientWebhookPayloadBuilder::extraForDeposit($deposit),
            WebhookClientMessages::getMessageForStatus('PAID_OUT', 'PIX_IN')
        );
    }
}
