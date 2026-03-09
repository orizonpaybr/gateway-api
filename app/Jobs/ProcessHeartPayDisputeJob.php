<?php

namespace App\Jobs;

use App\Helpers\WebhookClientMessages;
use App\Models\Solicitacoes;
use App\Models\WebhookLog;
use App\Services\PaymentProcessingService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processa webhooks de disputa (MED) da HeartPay:
 *  - DisputeCreated   → disputa aberta, valor bloqueado
 *  - DisputeCanceled  → disputa cancelada, valor desbloqueado
 *
 * Persiste em pix_infracoes e notifica o cliente.
 */
class ProcessHeartPayDisputeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        public string $event,
        public array $data,
        public int $webhookLogId
    ) {}

    public function handle(PaymentProcessingService $paymentService): void
    {
        $jobStart = microtime(true);

        $inner = $this->data['data'] ?? $this->data;
        $correlationID = $inner['correlationID'] ?? null;
        $endToEndId    = $inner['endToEndId'] ?? null;

        if (!$correlationID && !$endToEndId) {
            Log::warning('[HeartPay Dispute Job] Payload sem identificador', [
                'event' => $this->event,
            ]);
            $this->failWebhookLog('Sem correlationID ou endToEndId');
            return;
        }

        $cashin = null;
        if ($correlationID) {
            $cashin = Solicitacoes::where('idTransaction', $correlationID)
                ->orWhere('externalreference', $correlationID)
                ->first();
        }
        if (!$cashin && $endToEndId) {
            $cashin = Solicitacoes::where('end_to_end', $endToEndId)->first();
        }

        $username = $cashin->user_id ?? null;
        $transactionId = $correlationID ?? $endToEndId;

        if (!$username) {
            Log::warning('[HeartPay Dispute Job] Transação não encontrada', [
                'correlationID' => $correlationID,
                'endToEndId' => $endToEndId,
            ]);
            $this->failWebhookLog('Transação não encontrada para disputa');
            return;
        }

        if ($this->event === 'DisputeCreated') {
            $this->handleCreated($username, $transactionId, $endToEndId, $inner, $cashin, $paymentService, $jobStart);
        } else {
            $this->handleCanceled($username, $transactionId, $endToEndId, $inner, $cashin, $paymentService, $jobStart);
        }
    }

    private function handleCreated(
        string $username, string $transactionId, ?string $endToEndId,
        array $inner, ?Solicitacoes $cashin, PaymentProcessingService $paymentService, float $jobStart
    ): void {
        $valueCents    = $inner['value'] ?? $inner['disputeValue'] ?? 0;
        $valorReais    = abs((float) $valueCents) / 100;
        $reason        = $inner['reason'] ?? 'MED - Mecanismo Especial de Devolução';
        $chargeStatus  = $inner['chargeStatus'] ?? 'BLOCKED';
        $createdAt     = $inner['createdAt'] ?? now()->toIso8601String();

        $detalhes = json_encode([
            'heartpay_event'   => $this->event,
            'disputeValue'     => $inner['disputeValue'] ?? null,
            'chargeStatus'     => $chargeStatus,
            'reason'           => $reason,
            'provider'         => $inner['provider'] ?? null,
            'status'           => $inner['status'] ?? 'OPENED',
        ], JSON_UNESCAPED_UNICODE);

        $exists = DB::table('pix_infracoes')
            ->where('user_id', $username)
            ->where('transaction_id', $transactionId)
            ->exists();

        $values = [
            'status'                => 'PENDENTE',
            'tipo'                  => 'fraude',
            'descricao'             => $reason,
            'descricao_normalizada' => "MED HeartPay | {$reason}",
            'valor'                 => $valorReais,
            'end_to_end'            => $endToEndId,
            'data_criacao'          => Carbon::parse($createdAt),
            'data_limite'           => Carbon::parse($createdAt)->addDays(7),
            'detalhes'              => $detalhes,
            'updated_at'            => now(),
        ];

        if ($exists) {
            DB::table('pix_infracoes')
                ->where('user_id', $username)
                ->where('transaction_id', $transactionId)
                ->update($values);
        } else {
            DB::table('pix_infracoes')->insert(array_merge([
                'user_id'        => $username,
                'transaction_id' => $transactionId,
                'created_at'     => Carbon::parse($createdAt),
            ], $values));
        }

        if ($cashin && !in_array($cashin->status, ['MEDIATION', 'BLOCKED'])) {
            $cashin->update(['status' => 'MEDIATION']);
        }

        $paymentService->invalidateInfractionCaches($username);

        Log::info('[HeartPay Dispute Job] DisputeCreated processado', [
            'transactionId' => $transactionId,
            'username' => $username,
            'valor' => $valorReais,
            'duration_ms' => round((microtime(true) - $jobStart) * 1000, 2),
        ]);

        $this->markWebhookProcessed();
        $this->notifyClient($cashin, $transactionId, 'INFRACTION_OPEN', $valorReais, $endToEndId, $reason);
    }

    private function handleCanceled(
        string $username, string $transactionId, ?string $endToEndId,
        array $inner, ?Solicitacoes $cashin, PaymentProcessingService $paymentService, float $jobStart
    ): void {
        DB::table('pix_infracoes')
            ->where('user_id', $username)
            ->where('transaction_id', $transactionId)
            ->update([
                'status'     => 'CANCELADA',
                'updated_at' => now(),
            ]);

        if ($cashin && $cashin->status === 'MEDIATION') {
            $cashin->update(['status' => 'PAID_OUT']);
        }

        $paymentService->invalidateInfractionCaches($username);

        Log::info('[HeartPay Dispute Job] DisputeCanceled processado — valor liberado', [
            'transactionId' => $transactionId,
            'username' => $username,
            'duration_ms' => round((microtime(true) - $jobStart) * 1000, 2),
        ]);

        $this->markWebhookProcessed();
        $this->notifyClient($cashin, $transactionId, 'INFRACTION_CANCELLED', 0, $endToEndId, 'Disputa cancelada/resolvida a favor do seller.');
    }

    private function notifyClient(
        ?Solicitacoes $cashin, string $transactionId, string $statusKey,
        float $valor, ?string $endToEndId, string $reason
    ): void {
        $callbackUrl = $cashin->callback ?? null;
        if (empty($callbackUrl) || $callbackUrl === 'web') {
            return;
        }

        $message = WebhookClientMessages::getMessageForStatus($statusKey);

        ClientWebhookDispatchJob::dispatch(
            $callbackUrl,
            $transactionId,
            $statusKey,
            $valor,
            now()->toIso8601String(),
            [
                'typeTransaction'  => 'INFRACTION',
                'infractionStatus' => $statusKey,
                'description'      => $reason,
                'endToEndId'       => $endToEndId,
                'relatedUser'      => $cashin->user_id ?? null,
            ],
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
