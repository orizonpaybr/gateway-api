<?php

namespace App\Jobs;

use App\Helpers\WebhookClientMessages;
use App\Models\Solicitacoes;
use App\Models\WebhookLog;
use App\Services\HeartPayService;
use App\Services\PaymentProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Processa webhooks de Cash In da HeartPay:
 *  - PayInCompleted  → depósito confirmado, credita saldo
 *  - PayInCancelled  → cobrança expirada/cancelada
 *
 * Payload HeartPay: data.data contém os campos.
 * amount em centavos, endToEndId, payer, customer, etc.
 */
class ProcessHeartPayCashInJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        public string $event,
        public string $correlationID,
        public array $data,
        public int $webhookLogId
    ) {}

    public function handle(PaymentProcessingService $paymentService): void
    {
        $jobStart = microtime(true);
        $inner = $this->data['data'] ?? $this->data;

        $cashin = Solicitacoes::where('idTransaction', $this->correlationID)
            ->orWhere('externalreference', $this->correlationID)
            ->first();

        if (!$cashin) {
            $txid = $inner['txid'] ?? null;
            if ($txid && $txid !== $this->correlationID) {
                $cashin = Solicitacoes::where('idTransaction', $txid)
                    ->orWhere('externalreference', $txid)
                    ->first();
            }
        }

        if (!$cashin) {
            Log::warning('[HeartPay CashIn Job] Solicitação não encontrada', [
                'correlationID' => $this->correlationID,
                'event' => $this->event,
            ]);
            $this->failWebhookLog('Transação não encontrada');
            return;
        }

        if ($this->event === 'PayInCompleted') {
            $this->handleCompleted($cashin, $inner, $paymentService, $jobStart);
        } elseif ($this->event === 'PayInCancelled') {
            $this->handleCancelled($cashin, $inner, $jobStart);
        } else {
            $this->handleCreated($cashin, $inner, $jobStart);
        }
    }

    private function handleCompleted(Solicitacoes $cashin, array $inner, PaymentProcessingService $paymentService, float $jobStart): void
    {
        if (in_array($cashin->status, ['PAID_OUT', 'COMPLETED'])) {
            Log::info('[HeartPay CashIn Job] Pagamento já processado (idempotência)', [
                'correlationID' => $this->correlationID,
            ]);
            $this->markWebhookProcessed();
            return;
        }

        $webhookAmountCents = $inner['amount'] ?? $inner['amountReceived'] ?? null;
        if ($webhookAmountCents !== null) {
            $webhookAmountReais = HeartPayService::toReais((int) $webhookAmountCents);
            $storedAmount = (float) $cashin->amount;
            if (abs($webhookAmountReais - $storedAmount) > 0.01) {
                Log::warning('[HeartPay CashIn Job] Divergência de valor', [
                    'correlationID' => $this->correlationID,
                    'webhook_reais' => $webhookAmountReais,
                    'stored_amount' => $storedAmount,
                ]);
                if (config('heartpay.strict_amount_validation', false)) {
                    $this->failWebhookLog("Divergência de valor: webhook {$webhookAmountReais} != banco {$storedAmount}");
                    return;
                }
            }
        }

        $endToEndId = $inner['endToEndId'] ?? null;
        if ($endToEndId) {
            $cashin->end_to_end = $endToEndId;
        }

        $payerName = $inner['payer']['name'] ?? $inner['customer']['name'] ?? null;
        if ($payerName) {
            $cashin->payer_name = $payerName;
        }

        $cashin->save();

        try {
            $paymentService->processPaymentReceived($cashin);

            Log::info('[HeartPay CashIn Job] Pagamento processado', [
                'correlationID' => $this->correlationID,
                'amount' => $cashin->amount,
                'duration_ms' => round((microtime(true) - $jobStart) * 1000, 2),
            ]);

            $this->markWebhookProcessed();
            $this->notifyClient($cashin, 'PAID_OUT', 'PIX_IN', $inner);
        } catch (\Throwable $e) {
            Log::error('[HeartPay CashIn Job] Erro ao processar pagamento', [
                'correlationID' => $this->correlationID,
                'error' => $e->getMessage(),
            ]);
            $this->failWebhookLog($e->getMessage());
            throw $e;
        }
    }

    private function handleCancelled(Solicitacoes $cashin, array $inner, float $jobStart): void
    {
        if (in_array($cashin->status, ['CANCELLED', 'PAID_OUT', 'COMPLETED'])) {
            $this->markWebhookProcessed();
            return;
        }

        $cashin->update(['status' => 'CANCELLED']);

        Log::info('[HeartPay CashIn Job] Cobrança cancelada/expirada', [
            'correlationID' => $this->correlationID,
            'duration_ms' => round((microtime(true) - $jobStart) * 1000, 2),
        ]);

        $this->markWebhookProcessed();
        $this->notifyClient($cashin, 'CANCELLED', 'PIX_IN', $inner);
    }

    private function handleCreated(Solicitacoes $cashin, array $inner, float $jobStart): void
    {
        Log::info('[HeartPay CashIn Job] PayInCreated recebido (apenas log)', [
            'correlationID' => $this->correlationID,
        ]);
        $this->markWebhookProcessed();
    }

    private function notifyClient(Solicitacoes $cashin, string $status, string $type, array $inner): void
    {
        $callbackUrl = $cashin->callback;
        if (empty($callbackUrl) || $callbackUrl === 'web') {
            return;
        }

        $message = WebhookClientMessages::getMessageForStatus($status, $type);

        $payer = $inner['payer'] ?? $inner['customer'] ?? [];

        $extra = [
            'typeTransaction' => $type,
            'payer' => [
                'name'     => $payer['name'] ?? $cashin->payer_name ?? $cashin->client_name ?? null,
                'document' => $payer['taxID'] ?? $cashin->client_document ?? null,
                'email'    => $payer['email'] ?? $cashin->client_email ?? null,
                'phone'    => $payer['phone'] ?? $cashin->client_telefone ?? null,
            ],
            'receiver' => [
                'user_id' => $cashin->user_id,
            ],
            'endToEndId' => $inner['endToEndId'] ?? $cashin->end_to_end ?? null,
        ];

        ClientWebhookDispatchJob::dispatch(
            $callbackUrl,
            $cashin->idTransaction,
            $status,
            (float) $cashin->amount,
            $inner['paidAt'] ?? now()->toIso8601String(),
            $extra,
            $message
        )->onQueue('webhooks');
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
