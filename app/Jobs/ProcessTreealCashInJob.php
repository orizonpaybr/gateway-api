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

        // Conformidade: validar valor do webhook vs valor armazenado
        $webhookLog = WebhookLog::find($this->webhookLogId);
        $payload = $webhookLog?->payload ?? [];
        $webhookAmount = $this->extractAmountFromPayload($payload);
        $storedAmount = (float) $cashin->amount;
        if ($webhookAmount !== null && !$this->amountsMatch($webhookAmount, $storedAmount)) {
            Log::warning('[TREEAL CashIn Job] Divergência de valor (webhook vs banco)', [
                'txid'          => $this->txid,
                'webhook_amount' => $webhookAmount,
                'stored_amount'  => $storedAmount,
            ]);
            if (config('treeal.strict_amount_validation', false)) {
                $this->failWebhookLog('Divergência de valor: webhook ' . $webhookAmount . ' != banco ' . $storedAmount);
                return;
            }
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

    /** Extrai valor monetário do payload do webhook Treeal (Cash In). */
    private function extractAmountFromPayload(array $payload): ?float
    {
        $valor = $payload['valor'] ?? $payload['amount'] ?? null;
        if ($valor === null && isset($payload['data']) && is_array($payload['data'])) {
            $valor = $payload['data']['valor'] ?? $payload['data']['amount'] ?? null;
        }
        if ($valor === null) {
            return null;
        }
        return (float) $valor;
    }

    /** Compara dois valores com tolerância para arredondamento (2 casas). */
    private function amountsMatch(float $a, float $b): bool
    {
        return abs(round($a, 2) - round($b, 2)) < 0.005;
    }
}
