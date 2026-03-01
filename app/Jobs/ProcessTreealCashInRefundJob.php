<?php

namespace App\Jobs;

use App\Helpers\WebhookClientMessages;
use App\Jobs\ClientWebhookDispatchJob;
use App\Models\Solicitacoes;
use App\Models\WebhookLog;
use App\Services\BalanceService;
use App\Services\PaymentProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processa estorno de depósito PIX (Cash In) Treeal.
 *
 * Quando a Treeal envia webhook REFUND com txid preenchido, este job:
 * - Localiza a solicitação de depósito pelo txid
 * - Se estava PAID_OUT/COMPLETED: debita o valor creditado (deposito_liquido) do saldo do usuário
 * - Atualiza status da transação para REFUNDED
 * - Invalida caches (dashboard, saldo, etc.)
 *
 * Idempotente: se a transação já estiver REFUNDED, não debita novamente.
 */
class ProcessTreealCashInRefundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public string $txid,
        public int $webhookLogId
    ) {}

    public function handle(BalanceService $balanceService, PaymentProcessingService $paymentService): void
    {
        $cashin = Solicitacoes::where('idTransaction', $this->txid)
            ->orWhere('externalreference', $this->txid)
            ->first();

        if (!$cashin) {
            Log::warning('[TREEAL CashIn Refund] Solicitação não encontrada', ['txid' => $this->txid]);
            $this->failWebhookLog('Transação não encontrada');
            return;
        }

        if ($cashin->status === 'REFUNDED' || $cashin->status === 'PARTIALLY_REFUNDED') {
            Log::info('[TREEAL CashIn Refund] Estorno já processado (idempotência)', ['txid' => $this->txid]);
            $this->markWebhookProcessed();
            return;
        }

        $wasPaid = in_array($cashin->status, ['PAID_OUT', 'COMPLETED']);
        $valorReverter = (float) $cashin->deposito_liquido;

        if (!$wasPaid) {
            $cashin->update(['status' => 'REFUNDED']);
            Log::info('[TREEAL CashIn Refund] Transação não estava paga; apenas status atualizado', [
                'txid'   => $this->txid,
                'status' => $cashin->status,
            ]);
            $this->notifyClientRefund($cashin);
            $this->markWebhookProcessed();
            return;
        }

        try {
            /** @var BalanceService $balanceService */
            $svc = $balanceService;
            DB::transaction(function () use ($cashin, $valorReverter, $svc) {
                $user = \App\Models\User::where('user_id', $cashin->user_id)
                    ->lockForUpdate()
                    ->first();

                if (!$user) {
                    throw new \Exception("Usuário não encontrado: {$cashin->user_id}");
                }

                $svc->decrementBalanceForRefund($user, $valorReverter, 'saldo');
                $cashin->update(['status' => 'REFUNDED']);
            });

            $paymentService->invalidateCachesAfterPayment($cashin->user_id);

            Log::info('[TREEAL CashIn Refund] Estorno processado com sucesso', [
                'txid'           => $this->txid,
                'user_id'        => $cashin->user_id,
                'valor_debitado' => $valorReverter,
            ]);
            $this->notifyClientRefund($cashin);
            $this->markWebhookProcessed();
        } catch (\Throwable $e) {
            Log::error('[TREEAL CashIn Refund] Erro ao processar', [
                'txid'  => $this->txid,
                'error' => $e->getMessage(),
            ]);
            $this->failWebhookLog($e->getMessage());
            throw $e;
        }
    }

    private function markWebhookProcessed(): void
    {
        WebhookLog::where('id', $this->webhookLogId)->update(['status' => 'PROCESSED']);
    }

    private function failWebhookLog(string $error): void
    {
        WebhookLog::where('id', $this->webhookLogId)->update([
            'status' => 'FAILED',
            'error'  => $error,
        ]);
    }

    /**
     * Repassa o estorno de depósito para a URL de callback do cliente (orquestrador → cliente final).
     */
    private function notifyClientRefund(Solicitacoes $cashin): void
    {
        if (empty($cashin->callback) || $cashin->callback === 'web') {
            return;
        }

        $extra = [
            'typeTransaction' => 'PIX_IN',
            'txid'            => $this->txid,
            'endToEndId'      => $cashin->end_to_end ?? null,
            'payer'           => [
                'name'     => $cashin->client_name ?? null,
                'document' => $cashin->client_document ?? null,
                'email'    => $cashin->client_email ?? null,
                'phone'    => $cashin->client_telefone ?? null,
            ],
            'receiver' => [
                'user_id' => $cashin->user_id ?? null,
            ],
        ];
        $message = WebhookClientMessages::getMessageForStatus('REFUNDED', 'PIX_IN', null);
        ClientWebhookDispatchJob::dispatch(
            $cashin->callback,
            $cashin->idTransaction ?? (string) $cashin->id,
            'REFUNDED',
            (float) $cashin->amount,
            now()->toIso8601String(),
            $extra,
            $message
        )->onQueue('webhooks');
    }
}
