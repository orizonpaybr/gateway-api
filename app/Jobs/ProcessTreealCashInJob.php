<?php

namespace App\Jobs;

use App\Models\Solicitacoes;
use App\Models\WebhookLog;
use App\Services\PaymentProcessingService;
use App\Jobs\ClientWebhookDispatchJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Processa crédito de depósito PIX Treeal em background.
 *
 * Permite que o webhook responda 200 imediatamente à Treeal (evita retentativas)
 * e o crédito ao saldo ocorra em poucos segundos via fila.
 */
class ProcessTreealCashInJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public string $txid,
        public int $webhookLogId
    ) {
        // Usar fila padrão; para priorizar: $this->onQueue('webhooks') e rodar php artisan queue:work --queue=webhooks,default
    }

    public function handle(PaymentProcessingService $paymentService): void
    {
        $jobStart = microtime(true);

        $cashin = Solicitacoes::where('idTransaction', $this->txid)
            ->orWhere('externalreference', $this->txid)
            ->first();

        if (!$cashin) {
            Log::warning('[TREEAL CashIn Job] Solicitação não encontrada', ['txid' => $this->txid]);
            $this->failWebhookLog('Transação não encontrada');
            return;
        }

        if ($cashin->status === 'PAID_OUT' || $cashin->status === 'COMPLETED') {
            Log::info('[TREEAL CashIn Job] Pagamento já processado (idempotência)', ['txid' => $this->txid]);
            $this->markWebhookProcessed();
            return;
        }

        try {
            $paymentService->processPaymentReceived($cashin);
            Log::info('[TREEAL CashIn Job] Pagamento processado com sucesso', [
                'txid'        => $this->txid,
                'amount'      => $cashin->amount,
                'duration_ms' => round((microtime(true) - $jobStart) * 1000, 2),
            ]);

            if (!empty($cashin->callback) && $cashin->callback !== 'web') {
                $extra = [
                    'typeTransaction' => 'PIX_IN',
                    'payer' => [
                        'name'     => $cashin->client_name ?? null,
                        'document' => $cashin->client_document ?? null,
                        'email'    => $cashin->client_email ?? null,
                        'phone'    => $cashin->client_telefone ?? null,
                    ],
                    'receiver' => [
                        'user_id' => $cashin->user_id ?? null,
                    ],
                ];
                ClientWebhookDispatchJob::dispatch(
                    $cashin->callback,
                    $cashin->idTransaction ?? (string) $cashin->id,
                    'PAID_OUT',
                    (float) $cashin->amount,
                    now()->toIso8601String(),
                    $extra
                )->onQueue('webhooks');
            }

            $this->markWebhookProcessed();
        } catch (\Throwable $e) {
            Log::error('[TREEAL CashIn Job] Erro ao processar', [
                'txid'        => $this->txid,
                'error'       => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $jobStart) * 1000, 2),
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
            'error' => $error,
        ]);
    }
}
