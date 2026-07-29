<?php

namespace App\Services\FluxPayments;

use App\Helpers\WebhookClientMessages;
use App\Jobs\ClientWebhookDispatchJob;
use App\Models\Solicitacoes;
use App\Services\ClientWebhookPayloadBuilder;
use App\Services\PaymentProcessingService;
use App\Services\PixAcquirer\PixAcquirerManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Quando o PIX foi pago na adquirente (FluxPayments / Paya55) mas o postback não chegou,
 * consulta GET /api/v1/transactions/pix-in e liquida o depósito.
 * Resolve a nominal via adquirente_ref (não o singleton .env).
 */
class FluxPaymentsDepositReconciler
{
    public function reconcileIfPaid(Solicitacoes $deposit): bool
    {
        $provider = strtolower(trim((string) ($deposit->executor_ordem ?? '')));
        if (! in_array($provider, FluxPaymentsPixAcquirerService::FAMILY, true)) {
            return false;
        }

        $tag = '['.strtoupper($provider).'][RECONCILE]';

        if (! in_array($deposit->status, ['WAITING_FOR_APPROVAL'], true)) {
            return false;
        }

        $txid = trim((string) ($deposit->idTransaction ?? ''));
        if ($txid === '') {
            return false;
        }

        $nominal = strtolower(trim((string) ($deposit->adquirente_ref ?? '')));
        $flux = app(PixAcquirerManager::class)->resolve($nominal !== '' ? $nominal : $provider);

        if (! $flux instanceof FluxPaymentsPixAcquirerService || ! $flux->isActive()) {
            Log::warning($tag.' Adquirente inativa para depósito', [
                'deposit_id' => $deposit->id,
                'adquirente_ref' => $deposit->adquirente_ref,
            ]);

            return false;
        }

        $result = $flux->getChargeStatus($txid);
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
            Log::error($tag.' Falha ao creditar depósito', [
                'deposit_id' => $deposit->id,
                'txid' => $txid,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $fresh = $deposit->fresh();
        $this->dispatchClientWebhook($fresh, is_string($paidAt) ? $paidAt : null);

        Log::info($tag.' Depósito liquidado via consulta pix-in', [
            'deposit_id' => $deposit->id,
            'txid' => $txid,
            'user_id' => $deposit->user_id,
            'adquirente_ref' => $deposit->adquirente_ref,
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
