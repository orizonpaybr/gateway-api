<?php

namespace App\Jobs;

use App\Helpers\WebhookClientMessages;
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
 * Processa webhooks de Cash Out da HeartPay:
 *  - PayOutCompleted  → saque liquidado
 *  - PayOutFailed     → saque falhou (valor devolvido ao saldo automaticamente pela HeartPay)
 *  - PAYOUT_APPROVED  → saque aprovado (modo manual)
 *  - PAYOUT_REJECTED  → saque rejeitado
 *  - PAYOUT_CREATED   → saque criado (apenas log)
 *
 * Payload: data.data contém os campos.
 * value em centavos; amount, netAmount, feeAmount em reais.
 * correlationID/referenceCode para match com o registro local.
 */
class ProcessHeartPayCashOutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        public string $event,
        public string $transactionRef,
        public array $data,
        public int $webhookLogId
    ) {}

    public function handle(): void
    {
        $jobStart = microtime(true);
        $inner = $this->data['data'] ?? $this->data;

        $saque = $this->findSaque($inner);

        if (!$saque) {
            Log::warning('[HeartPay CashOut Job] Saque não encontrado', [
                'event' => $this->event,
                'transactionRef' => $this->transactionRef,
            ]);
            $this->failWebhookLog('Saque não encontrado');
            return;
        }

        match ($this->event) {
            'PayOutCompleted', 'PAYOUT_COMPLETED' => $this->handleCompleted($saque, $inner, $jobStart),
            'PayOutFailed', 'PAYOUT_FAILED'       => $this->handleFailed($saque, $inner, $jobStart),
            'PAYOUT_APPROVED'                      => $this->handleApproved($saque, $inner, $jobStart),
            'PAYOUT_REJECTED'                      => $this->handleRejected($saque, $inner, $jobStart),
            'PAYOUT_CREATED'                       => $this->handleCreated($saque, $jobStart),
            default                                => $this->handleCreated($saque, $jobStart),
        };
    }

    private function handleCompleted(SolicitacoesCashOut $saque, array $inner, float $jobStart): void
    {
        if (in_array($saque->status, ['PAID_OUT', 'COMPLETED'])) {
            $this->markWebhookProcessed();
            return;
        }

        $endToEndId = $inner['endToEndId'] ?? null;

        $saque->update([
            'status'     => 'PAID_OUT',
            'end_to_end' => $endToEndId ?? $saque->end_to_end,
        ]);

        app(PaymentProcessingService::class)->processWithdrawal($saque);

        Log::info('[HeartPay CashOut Job] Saque liquidado', [
            'transactionRef' => $this->transactionRef,
            'amount' => $saque->amount,
            'duration_ms' => round((microtime(true) - $jobStart) * 1000, 2),
        ]);

        $this->markWebhookProcessed();
        $this->notifyClient($saque, 'PAID_OUT', $inner);
    }

    private function handleFailed(SolicitacoesCashOut $saque, array $inner, float $jobStart): void
    {
        if (in_array($saque->status, ['PAID_OUT', 'COMPLETED'])) {
            $this->markWebhookProcessed();
            return;
        }

        $errorMessage = $inner['errorMessage'] ?? $inner['error_message'] ?? null;

        $saque->update(['status' => 'FAILED']);

        $user = User::where('user_id', $saque->user_id)->first();
        if ($user) {
            $balanceService = app(BalanceService::class);
            $valorTotalDescontar = (float) $saque->amount + (float) ($saque->taxa_cash_out ?? 0);
            $balanceService->incrementBalance($user, $valorTotalDescontar);
            \App\Helpers\Helper::calculaSaldoLiquido($saque->user_id);
            app(PaymentProcessingService::class)->invalidateCachesAfterPayment($saque->user_id);
        }

        Log::info('[HeartPay CashOut Job] Saque falhou — saldo revertido', [
            'transactionRef' => $this->transactionRef,
            'amount' => $saque->amount,
            'errorMessage' => $errorMessage,
            'duration_ms' => round((microtime(true) - $jobStart) * 1000, 2),
        ]);

        $this->markWebhookProcessed();
        $this->notifyClient($saque, 'FAILED', $inner, $errorMessage);
    }

    private function handleApproved(SolicitacoesCashOut $saque, array $inner, float $jobStart): void
    {
        if (!in_array($saque->status, ['PENDING', 'PENDING_APPROVAL'])) {
            $this->markWebhookProcessed();
            return;
        }

        $saque->update(['status' => 'PROCESSING']);

        Log::info('[HeartPay CashOut Job] Saque aprovado', [
            'transactionRef' => $this->transactionRef,
        ]);

        $this->markWebhookProcessed();
        $this->notifyClient($saque, 'PROCESSING', $inner);
    }

    private function handleRejected(SolicitacoesCashOut $saque, array $inner, float $jobStart): void
    {
        if (in_array($saque->status, ['PAID_OUT', 'COMPLETED', 'CANCELLED'])) {
            $this->markWebhookProcessed();
            return;
        }

        $saque->update(['status' => 'CANCELLED']);

        $user = User::where('user_id', $saque->user_id)->first();
        if ($user) {
            $balanceService = app(BalanceService::class);
            $valorTotalDescontar = (float) $saque->amount + (float) ($saque->taxa_cash_out ?? 0);
            $balanceService->incrementBalance($user, $valorTotalDescontar);
            \App\Helpers\Helper::calculaSaldoLiquido($saque->user_id);
            app(PaymentProcessingService::class)->invalidateCachesAfterPayment($saque->user_id);
        }

        Log::info('[HeartPay CashOut Job] Saque rejeitado — saldo revertido', [
            'transactionRef' => $this->transactionRef,
        ]);

        $this->markWebhookProcessed();
        $this->notifyClient($saque, 'CANCELLED', $inner);
    }

    private function handleCreated(SolicitacoesCashOut $saque, float $jobStart): void
    {
        Log::info('[HeartPay CashOut Job] PAYOUT_CREATED recebido (apenas log)', [
            'transactionRef' => $this->transactionRef,
        ]);
        $this->markWebhookProcessed();
    }

    private function findSaque(array $inner): ?SolicitacoesCashOut
    {
        $refs = array_filter([
            $inner['correlationID'] ?? null,
            $inner['referenceCode'] ?? $inner['reference_code'] ?? null,
            $this->transactionRef,
        ]);

        foreach ($refs as $ref) {
            $saque = SolicitacoesCashOut::where('idTransaction', $ref)
                ->orWhere('externalreference', $ref)
                ->orWhere('descricao_externa', $ref)
                ->first();
            if ($saque) return $saque;
        }

        $endToEndId = $inner['endToEndId'] ?? null;
        if ($endToEndId) {
            return SolicitacoesCashOut::where('end_to_end', $endToEndId)->first();
        }

        return null;
    }

    private function notifyClient(SolicitacoesCashOut $saque, string $status, array $inner, ?string $errorMessage = null): void
    {
        $callbackUrl = $saque->callback;
        if (empty($callbackUrl) || $callbackUrl === 'web') {
            return;
        }

        $message = WebhookClientMessages::getMessageForStatus($status, 'PIX_OUT');

        $extra = [
            'typeTransaction' => 'PIX_OUT',
            'beneficiary' => [
                'name'     => $inner['recipientName'] ?? $saque->beneficiaryname ?? null,
                'document' => $inner['recipientDocument'] ?? $saque->beneficiarydocument ?? null,
                'pixKey'   => $saque->pix ?? null,
            ],
            'sender' => [
                'user_id' => $saque->user_id,
            ],
            'endToEndId' => $inner['endToEndId'] ?? $saque->end_to_end ?? null,
        ];

        if ($errorMessage) {
            $extra['error'] = $errorMessage;
        }

        ClientWebhookDispatchJob::dispatch(
            $callbackUrl,
            $saque->idTransaction,
            $status,
            (float) $saque->amount,
            $inner['completedAt'] ?? now()->toIso8601String(),
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
