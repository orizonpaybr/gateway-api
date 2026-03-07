<?php

namespace App\Jobs;

use App\Helpers\WebhookClientMessages;
use App\Jobs\ClientWebhookDispatchJob;
use App\Models\Solicitacoes;
use App\Models\WebhookLog;
use App\Services\PaymentProcessingService;
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

            $endToEndId = $payload['endToEndId'] ?? $payload['end_to_end_id'] ?? null;
            if ($endToEndId === null && isset($payload['data']) && is_array($payload['data'])) {
                $endToEndId = $payload['data']['endToEndId'] ?? $payload['data']['end_to_end_id'] ?? null;
            }
            $endToEndId = (is_string($endToEndId) && $endToEndId !== '') ? $endToEndId : null;

            $updateFields = [];
            if ($endToEndId !== null && empty($cashin->end_to_end)) {
                $updateFields['end_to_end'] = $endToEndId;
            }

            $realPayer = $this->extractPayerFromPayload($payload);
            if ($realPayer['name'] !== null) {
                $updateFields['payer_name'] = $realPayer['name'];
            }
            if ($realPayer['document'] !== null) {
                $updateFields['payer_document'] = $realPayer['document'];
            }

            if (!empty($updateFields)) {
                $cashin->update($updateFields);
                Log::info('[TREEAL CashIn Job] Dados adicionais atualizados', [
                    'txid'   => $this->txid,
                    'fields' => array_keys($updateFields),
                ]);
            }

            if (!empty($cashin->callback) && $cashin->callback !== 'web') {
                $extra = [
                    'typeTransaction' => 'PIX_IN',
                    'txid'            => $this->txid,
                    'endToEndId'      => $endToEndId,
                    'payer' => [
                        'name'     => $cashin->payer_name ?? $cashin->client_name ?? null,
                        'document' => $cashin->payer_document ?? $cashin->client_document ?? null,
                        'email'    => $cashin->client_email ?? null,
                        'phone'    => $cashin->client_telefone ?? null,
                    ],
                    'receiver' => [
                        'user_id' => $cashin->user_id ?? null,
                    ],
                ];
                $message = WebhookClientMessages::getMessageForStatus('PAID_OUT', 'PIX_IN', null);
                ClientWebhookDispatchJob::dispatch(
                    $cashin->callback,
                    $cashin->idTransaction ?? (string) $cashin->id,
                    'PAID_OUT',
                    (float) $cashin->amount,
                    now()->toIso8601String(),
                    $extra,
                    $message
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

    /**
     * Extrai dados do pagador real do payload do webhook PIX.
     *
     * Suporta múltiplos formatos:
     * - BACEN padrão: pix[0].pagador.nome / pagador.cpf / pagador.cnpj
     * - ONZ Accounts API wrapper: data.pagador.* ou data.payer.*
     * - Flat: pagador.* ou payer.* no nível raiz
     *
     * @return array{name: ?string, document: ?string}
     */
    private function extractPayerFromPayload(array $payload): array
    {
        $name = null;
        $document = null;

        $sources = [];

        // BACEN: pix[0].pagador
        if (isset($payload['pix']) && is_array($payload['pix'])) {
            $pix = $payload['pix'][0] ?? $payload['pix'];
            if (isset($pix['pagador']) && is_array($pix['pagador'])) {
                $sources[] = $pix['pagador'];
            }
        }

        // ONZ wrapper: data.pagador / data.payer
        if (isset($payload['data']) && is_array($payload['data'])) {
            $inner = $payload['data'];
            if (isset($inner['pagador']) && is_array($inner['pagador'])) {
                $sources[] = $inner['pagador'];
            }
            if (isset($inner['payer']) && is_array($inner['payer'])) {
                $sources[] = $inner['payer'];
            }
            // ONZ pode enviar pix[] dentro de data
            if (isset($inner['pix']) && is_array($inner['pix'])) {
                $pix = $inner['pix'][0] ?? $inner['pix'];
                if (isset($pix['pagador']) && is_array($pix['pagador'])) {
                    $sources[] = $pix['pagador'];
                }
            }
        }

        // Flat: pagador / payer no nível raiz
        if (isset($payload['pagador']) && is_array($payload['pagador'])) {
            $sources[] = $payload['pagador'];
        }
        if (isset($payload['payer']) && is_array($payload['payer'])) {
            $sources[] = $payload['payer'];
        }

        foreach ($sources as $src) {
            if ($name === null) {
                $name = $src['nome'] ?? $src['name'] ?? null;
            }
            if ($document === null) {
                $doc = $src['cpf'] ?? $src['cnpj'] ?? $src['document'] ?? $src['documento'] ?? null;
                if (is_string($doc) && $doc !== '') {
                    $document = preg_replace('/\D/', '', $doc);
                }
            }
            if ($name !== null && $document !== null) {
                break;
            }
        }

        return ['name' => $name, 'document' => $document];
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
