<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use App\Models\Solicitacoes;
use App\Models\User;
use App\Models\SolicitacoesCashOut;
use App\Helpers\Helper;
use App\Helpers\PixErrorCodes;
use App\Models\App;
use App\Helpers\SecureLog;
use App\Helpers\WebhookClientMessages;
use App\Jobs\ClientWebhookDispatchJob;
use App\Jobs\ProcessHeartPayCashInJob;
use App\Jobs\ProcessHeartPayCashOutJob;
use App\Jobs\ProcessHeartPayDisputeJob;
use App\Jobs\ProcessHeartPayRefundJob;
use App\Services\PaymentProcessingService;

class CallbackController extends Controller
{
    // ═══════════════════════════════════════════════════════════════════
    //  HeartPay Webhook
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Webhook da HeartPay para todos os eventos PIX.
     *
     * Fluxo assíncrono:
     * 1. WebhookService cria WebhookLog (QUEUED).
     * 2. Job é despachado para a fila conforme o tipo de evento.
     * 3. Responde 200 à HeartPay em ~50–100 ms.
     * 4. Job processa em background e marca PROCESSED/FAILED.
     */
    public function webhookHeartPay(Request $request)
    {
        $start          = microtime(true);
        $webhookService = app(\App\Services\WebhookService::class);

        return $webhookService->processWebhook($request, 'heartpay', function ($webhookLog) use ($request, $start) {
            $data  = $request->all();
            SecureLog::webhook('HEARTPAY', 'WEBHOOK', $data);

            $event = $data['event']
                ?? $data['data']['event']
                ?? $request->header('X-HeartPay-Event')
                ?? null;

            if (!$event) {
                Log::warning('[HEARTPAY] Webhook sem campo event', ['data' => $data]);
                return response()->json(['status' => false, 'message' => 'Campo event ausente'], 400);
            }

            Log::info('[HEARTPAY] Webhook recebido', [
                'event'  => $event,
                'is_test' => $request->header('X-HeartPay-Test') === 'true',
            ]);

            $innerData = $data['data'] ?? $data;

            return match ($event) {
                'PayInCreated'     => $this->heartPayDispatchCashIn($event, $innerData, $webhookLog, $start),
                'PayInCompleted'   => $this->heartPayDispatchCashIn($event, $innerData, $webhookLog, $start),
                'PayInCancelled'   => $this->heartPayDispatchCashIn($event, $innerData, $webhookLog, $start),
                'charge.paid'      => $this->heartPayDispatchCashIn('PayInCompleted', $innerData, $webhookLog, $start),
                'charge.expired'   => $this->heartPayDispatchCashIn('PayInCancelled', $innerData, $webhookLog, $start),
                'charge.refunded'  => $this->heartPayDispatchRefund('PayInRefunded', $innerData, $webhookLog, $start),

                'PayInRefunded'    => $this->heartPayDispatchRefund($event, $innerData, $webhookLog, $start),
                'PayOutRefunded'   => $this->heartPayDispatchRefund($event, $innerData, $webhookLog, $start),

                'PayOutCompleted', 'PAYOUT_COMPLETED',
                'payout.completed' => $this->heartPayDispatchCashOut('PayOutCompleted', $innerData, $webhookLog, $start),

                'PayOutFailed', 'PAYOUT_FAILED',
                'payout.failed'    => $this->heartPayDispatchCashOut('PayOutFailed', $innerData, $webhookLog, $start),

                'PAYOUT_CREATED'   => $this->heartPayDispatchCashOut($event, $innerData, $webhookLog, $start),
                'PAYOUT_APPROVED'  => $this->heartPayDispatchCashOut($event, $innerData, $webhookLog, $start),
                'PAYOUT_REJECTED'  => $this->heartPayDispatchCashOut($event, $innerData, $webhookLog, $start),

                'DisputeCreated'   => $this->heartPayDispatchDispute($event, $innerData, $webhookLog, $start),
                'DisputeCanceled'  => $this->heartPayDispatchDispute($event, $innerData, $webhookLog, $start),

                default => $this->heartPayUnknownEvent($event, $data, $start),
            };
        });
    }

    private function heartPayExtractCorrelation(array $inner): string
    {
        $nested = $inner['data'] ?? $inner;
        return $nested['correlationID']
            ?? $nested['correlation_id']
            ?? $nested['txid']
            ?? $nested['referenceCode']
            ?? $nested['reference_code']
            ?? 'unknown';
    }

    private function heartPayExtractTransactionRef(array $inner): string
    {
        $nested = $inner['data'] ?? $inner;
        return $nested['correlationID']
            ?? $nested['referenceCode']
            ?? $nested['reference_code']
            ?? $nested['txid']
            ?? $nested['endToEndId']
            ?? 'unknown';
    }

    private function heartPayDispatchCashIn(string $event, array $innerData, $webhookLog, float $start): array
    {
        $correlationID = $this->heartPayExtractCorrelation($innerData);

        ProcessHeartPayCashInJob::dispatch($event, $correlationID, $innerData, $webhookLog->id)
            ->onQueue('webhooks');

        Log::info('[HEARTPAY] CashIn enfileirado', [
            'event' => $event,
            'correlationID' => $correlationID,
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        ]);

        return ['async' => true, 'response' => response()->json(['received' => true])];
    }

    private function heartPayDispatchCashOut(string $event, array $innerData, $webhookLog, float $start): array
    {
        $transactionRef = $this->heartPayExtractTransactionRef($innerData);

        ProcessHeartPayCashOutJob::dispatch($event, $transactionRef, $innerData, $webhookLog->id)
            ->onQueue('webhooks');

        Log::info('[HEARTPAY] CashOut enfileirado', [
            'event' => $event,
            'transactionRef' => $transactionRef,
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        ]);

        return ['async' => true, 'response' => response()->json(['received' => true])];
    }

    private function heartPayDispatchRefund(string $event, array $innerData, $webhookLog, float $start): array
    {
        $correlationID = $this->heartPayExtractCorrelation($innerData);

        ProcessHeartPayRefundJob::dispatch($event, $correlationID, $innerData, $webhookLog->id)
            ->onQueue('webhooks');

        Log::info('[HEARTPAY] Refund enfileirado', [
            'event' => $event,
            'correlationID' => $correlationID,
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        ]);

        return ['async' => true, 'response' => response()->json(['received' => true])];
    }

    private function heartPayDispatchDispute(string $event, array $innerData, $webhookLog, float $start): array
    {
        ProcessHeartPayDisputeJob::dispatch($event, $innerData, $webhookLog->id)
            ->onQueue('webhooks');

        Log::info('[HEARTPAY] Dispute enfileirado', [
            'event' => $event,
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        ]);

        return ['async' => true, 'response' => response()->json(['received' => true])];
    }

    private function heartPayUnknownEvent(string $event, array $data, float $start)
    {
        Log::warning('[HEARTPAY] Evento desconhecido', [
            'event' => $event,
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        ]);

        return response()->json(['received' => true, 'message' => 'Evento não mapeado']);
    }

}