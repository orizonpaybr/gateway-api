<?php

namespace App\Jobs;

use App\Helpers\WebhookClientMessages;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\BalanceService;
use App\Services\HeartPayService;
use App\Services\PaymentProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Processa webhooks de reembolso da HeartPay:
 *  - PayInRefunded     → reembolso de depósito (debita saldo do seller)
 *  - PayOutRefunded    → destinatário devolveu PIX enviado (credita saldo do seller)
 *
 * PayInRefunded:  amount e refundedAmount em centavos.
 * PayOutRefunded: refundedAmount em reais, value em centavos.
 */
class ProcessHeartPayRefundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    /** Backoff exponencial (s): 1ª retry 10s, 2ª 30s, 3ª 90s */
    public array $backoff = [10, 30, 90];

    public function __construct(
        public string $event,
        public string $correlationID,
        public array $data,
        public int $webhookLogId
    ) {}

    public function handle(): void
    {
        $jobStart = microtime(true);
        $inner = $this->data['data'] ?? $this->data;

        if ($this->event === 'PayInRefunded') {
            $this->handlePayInRefunded($inner, $jobStart);
        } elseif ($this->event === 'PayOutRefunded') {
            $this->handlePayOutRefunded($inner, $jobStart);
        }
    }

    private function handlePayInRefunded(array $inner, float $jobStart): void
    {
        $cashin = Solicitacoes::where('idTransaction', $this->correlationID)
            ->orWhere('externalreference', $this->correlationID)
            ->first();

        if (!$cashin) {
            $txid = $inner['txid'] ?? null;
            if ($txid) {
                $cashin = Solicitacoes::where('idTransaction', $txid)
                    ->orWhere('externalreference', $txid)
                    ->first();
            }
        }

        if (!$cashin) {
            Log::warning('[HeartPay Refund Job] Depósito não encontrado para reembolso', [
                'correlationID' => $this->correlationID,
            ]);
            $this->failWebhookLog('Depósito não encontrado para reembolso');
            return;
        }

        $refundedAmountCents = $inner['refundedAmount'] ?? $inner['amount'] ?? 0;
        $refundedReais = HeartPayService::toReais((int) $refundedAmountCents);
        $originalCents = $inner['amount'] ?? 0;
        $originalReais = HeartPayService::toReais((int) $originalCents);

        $isPartial = $refundedReais < $originalReais && $refundedReais > 0;
        $newStatus = $isPartial ? 'PARTIALLY_REFUNDED' : 'REFUNDED';

        $cashin->update(['status' => $newStatus]);

        $user = User::where('user_id', $cashin->user_id)->first();
        if ($user) {
            $balanceService = app(BalanceService::class);
            $balanceService->decrementBalanceForRefund($user, $refundedReais);
            \App\Helpers\Helper::calculaSaldoLiquido($cashin->user_id);
            app(PaymentProcessingService::class)->invalidateCachesAfterPayment($cashin->user_id);
        }

        Log::info('[HeartPay Refund Job] PayInRefunded processado', [
            'correlationID' => $this->correlationID,
            'refunded_reais' => $refundedReais,
            'new_status' => $newStatus,
            'duration_ms' => round((microtime(true) - $jobStart) * 1000, 2),
        ]);

        $this->markWebhookProcessed();

        $callbackUrl = $cashin->callback;
        if (!empty($callbackUrl) && $callbackUrl !== 'web') {
            $message = WebhookClientMessages::getMessageForStatus($newStatus, 'PIX_IN');

            ClientWebhookDispatchJob::dispatch(
                $callbackUrl,
                $cashin->idTransaction,
                $newStatus,
                $refundedReais,
                $inner['refundedAt'] ?? now()->toIso8601String(),
                [
                    'typeTransaction' => 'PIX_IN',
                    'originalAmount'  => (float) $cashin->amount,
                    'refundedAmount'  => $refundedReais,
                    'isPartial'       => $isPartial,
                ],
                $message
            )->onQueue('webhooks');
        }
    }

    private function handlePayOutRefunded(array $inner, float $jobStart): void
    {
        $refs = array_filter([
            $inner['correlationID'] ?? null,
            $inner['referenceCode'] ?? $inner['reference_code'] ?? null,
            $this->correlationID,
        ]);

        $saque = null;
        foreach ($refs as $ref) {
            $saque = SolicitacoesCashOut::where('idTransaction', $ref)
                ->orWhere('externalreference', $ref)
                ->orWhere('descricao_externa', $ref)
                ->first();
            if ($saque) break;
        }

        if (!$saque) {
            Log::warning('[HeartPay Refund Job] Saque não encontrado para PayOutRefunded', [
                'correlationID' => $this->correlationID,
            ]);
            $this->failWebhookLog('Saque não encontrado para PayOutRefunded');
            return;
        }

        $refundedAmountReais = (float) ($inner['refundedAmount'] ?? 0);
        if ($refundedAmountReais <= 0) {
            $valueCents = $inner['value'] ?? 0;
            $refundedAmountReais = HeartPayService::toReais((int) $valueCents);
        }

        $originalAmount = (float) $saque->amount;
        $isPartial = $refundedAmountReais < $originalAmount && $refundedAmountReais > 0;
        $newStatus = $isPartial ? 'PARTIALLY_REFUNDED' : 'REFUNDED';

        $saque->update(['status' => $newStatus]);

        $user = User::where('user_id', $saque->user_id)->first();
        if ($user) {
            $balanceService = app(BalanceService::class);
            $balanceService->incrementBalance($user, $refundedAmountReais);
            \App\Helpers\Helper::calculaSaldoLiquido($saque->user_id);
            app(PaymentProcessingService::class)->invalidateCachesAfterPayment($saque->user_id);
        }

        Log::info('[HeartPay Refund Job] PayOutRefunded processado — saldo creditado', [
            'correlationID' => $this->correlationID,
            'refunded_reais' => $refundedAmountReais,
            'new_status' => $newStatus,
            'duration_ms' => round((microtime(true) - $jobStart) * 1000, 2),
        ]);

        $this->markWebhookProcessed();

        $callbackUrl = $saque->callback;
        if (!empty($callbackUrl) && $callbackUrl !== 'web') {
            $message = WebhookClientMessages::getMessageForStatus($newStatus, 'PIX_OUT');

            ClientWebhookDispatchJob::dispatch(
                $callbackUrl,
                $saque->idTransaction,
                $newStatus,
                $refundedAmountReais,
                now()->toIso8601String(),
                [
                    'typeTransaction'   => 'PIX_OUT',
                    'originalAmount'    => $originalAmount,
                    'refundedAmount'    => $refundedAmountReais,
                    'isPartial'         => $isPartial,
                    'refundEndToEndId'  => $inner['refundEndToEndId'] ?? null,
                ],
                $message
            )->onQueue('webhooks');
        }
    }

    private function markWebhookProcessed(): void
    {
        WebhookLog::where('id', $this->webhookLogId)->update(['status' => 'PROCESSED']);
    }

    private function failWebhookLog(string $error): void
    {
        WebhookLog::where('id', $this->webhookLogId)->update(['status' => 'FAILED', 'error' => $error]);
    }
}
