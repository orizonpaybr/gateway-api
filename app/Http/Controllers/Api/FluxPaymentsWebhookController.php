<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Helpers\WebhookClientMessages;
use App\Http\Controllers\Controller;
use App\Jobs\ClientWebhookDispatchJob;
use App\Models\Adquirente;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Services\ClientWebhookPayloadBuilder;
use App\Services\FluxPayments\FluxPaymentsCashOutOutcomeService;
use App\Services\FluxPayments\FluxPaymentsInfractionService;
use App\Services\FluxPayments\FluxPaymentsPixAcquirerService;
use App\Services\PaymentProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhooks FluxPayments (envelope oficial: id, type, event, objectId, data).
 *
 * Processados (paridade Simpay/Fyhub/Treeal):
 * - PIX IN: paid, failed, cancelled, expired, refunded, partially_refunded, chargeback
 * - PIX OUT: paid, failed, cancelled, refunded, rejected
 * - MED: transaction.infraction (bloqueio) + refund/chargeback em mediação (estorno)
 *
 * ACK-only (sem mudança de status):
 * - transfer.created, transfer.processing, transfer.pending_approval, transfer.approved
 * - transaction.dispute, transaction.protest, transaction.blocked
 */
class FluxPaymentsWebhookController extends Controller
{
    /** @var list<string> */
    private const CASH_IN_TERMINAL_EVENTS = [
        'transaction.paid',
        'transaction.failed',
        'transaction.cancelled',
        'transaction.expired',
        'transaction.refunded',
        'transaction.partially_refunded',
        'transaction.chargeback',
    ];

    /** @var list<string> */
    private const CASH_OUT_TERMINAL_EVENTS = [
        'transfer.paid',
        'transfer.failed',
        'transfer.cancelled',
        'transfer.refunded',
        'transfer.partially_refunded',
        'transfer.rejected',
    ];

    /** @var list<string> */
    private const ACK_ONLY_EVENTS = [
        'transfer.created',
        'transfer.processing',
        'transfer.pending_approval',
        'transfer.approved',
        'transaction.dispute',
        'transaction.protest',
        'transaction.blocked',
    ];

    public function handle(Request $request): JsonResponse
    {
        if (! $this->passesOptionalSignature($request)) {
            Log::warning('[FLUXPAYMENTS][WEBHOOK] Assinatura inválida');

            return response()->json(['received' => false, 'processed' => false, 'reason' => 'invalid_signature'], 401);
        }

        $envelope = $request->all();
        $event = strtolower(trim((string) ($envelope['event'] ?? '')));
        $objectType = strtolower(trim((string) ($envelope['type'] ?? '')));
        $objectId = trim((string) ($envelope['objectId'] ?? ''));
        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];

        // Fallback: payload sem envelope (só o objeto da transação/transferência)
        if ($data === [] && isset($envelope['id'], $envelope['status'])) {
            $data = $envelope;
            if ($event === '') {
                $event = $this->inferEventFromStatus((string) ($data['status'] ?? ''), $objectType);
            }
        }

        if ($event === '' && isset($data['status'])) {
            $event = $this->inferEventFromStatus((string) $data['status'], $objectType);
        }

        // Propaga event/objectId para helpers de e2e/status
        $data['_event'] = $event;
        if ($objectId !== '' && empty($data['id'])) {
            $data['id'] = $objectId;
        }

        Log::info('[FLUXPAYMENTS][WEBHOOK] Evento recebido', [
            'event_id' => $envelope['id'] ?? null,
            'event' => $event,
            'type' => $objectType,
            'objectId' => $objectId !== '' ? $objectId : ($data['id'] ?? null),
            'status' => $data['status'] ?? null,
            'paymentMethod' => $data['paymentMethod'] ?? null,
        ]);

        if ($event === '') {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'missing_event']);
        }

        if (in_array($event, self::ACK_ONLY_EVENTS, true)) {
            Log::info('[FLUXPAYMENTS][WEBHOOK] Evento informativo (ACK)', [
                'event' => $event,
                'objectId' => $objectId !== '' ? $objectId : ($data['id'] ?? null),
            ]);

            return response()->json(['received' => true, 'processed' => true, 'reason' => 'ack_only']);
        }

        if ($event === 'transaction.infraction') {
            $result = app(FluxPaymentsInfractionService::class)->handleFromWebhook($data, $objectId);

            return response()->json([
                'received' => true,
                'processed' => (bool) ($result['processed'] ?? false),
                'reason' => $result['reason'] ?? null,
            ]);
        }

        if (str_starts_with($event, 'transfer.') || $objectType === 'transfer') {
            return $this->handleCashOut($event, $data, $objectId);
        }

        if (in_array($event, self::CASH_IN_TERMINAL_EVENTS, true)
            || str_starts_with($event, 'transaction.')
            || $objectType === 'transaction') {
            return $this->handleCashIn($event, $data, $objectId);
        }

        Log::info('[FLUXPAYMENTS][WEBHOOK] Evento não mapeado', ['event' => $event]);

        return response()->json(['received' => true, 'processed' => false, 'reason' => 'unhandled_event']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleCashIn(string $event, array $data, string $objectId): JsonResponse
    {
        $transactionId = trim((string) ($data['id'] ?? $objectId));
        $externalRef = trim((string) ($data['externalRef'] ?? ''));

        if ($transactionId === '' && $externalRef === '') {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'missing_id']);
        }

        $deposit = $this->findDeposit($transactionId, $externalRef);
        if (! $deposit) {
            Log::warning('[FLUXPAYMENTS][WEBHOOK] Depósito não encontrado', [
                'event' => $event,
                'id' => $transactionId !== '' ? $transactionId : null,
                'externalRef' => $externalRef !== '' ? $externalRef : null,
            ]);

            return response()->json(['received' => true, 'processed' => false, 'reason' => 'not_found']);
        }

        $mapped = $this->mapCashInEvent($event, $data);

        return match ($mapped) {
            'PAID_OUT' => $this->processPaid($deposit, $data),
            'REFUNDED' => $this->processRefunded($deposit, $data),
            'CANCELED', 'FAILED' => $this->processCanceled($deposit, $data, $mapped),
            default => response()->json([
                'received' => true,
                'processed' => false,
                'reason' => 'status_ignored',
                'mapped' => $mapped,
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleCashOut(string $event, array $data, string $objectId): JsonResponse
    {
        if (! in_array($event, self::CASH_OUT_TERMINAL_EVENTS, true)
            && ! in_array($event, ['transfer.paid', 'transfer.failed', 'transfer.cancelled', 'transfer.refunded', 'transfer.rejected'], true)) {
            // created/processing/pending_approval/approved já caem em ACK_ONLY;
            // qualquer outro transfer.* não terminal: ACK
            if (! in_array($event, self::CASH_OUT_TERMINAL_EVENTS, true)) {
                return response()->json(['received' => true, 'processed' => true, 'reason' => 'ack_only']);
            }
        }

        $transactionId = trim((string) ($data['id'] ?? $objectId));
        $externalRef = trim((string) ($data['externalRef'] ?? ''));

        $payout = $this->findCashOut($transactionId, $externalRef);
        if (! $payout) {
            Log::warning('[FLUXPAYMENTS][WEBHOOK] Saque não encontrado', [
                'event' => $event,
                'id' => $transactionId !== '' ? $transactionId : null,
                'externalRef' => $externalRef !== '' ? $externalRef : null,
            ]);

            return response()->json(['received' => true, 'processed' => false, 'reason' => 'cashout_not_found']);
        }

        $mapped = $this->mapCashOutEvent($event, $data);
        if (! in_array($mapped, ['COMPLETED', 'FAILED', 'CANCELLED', 'REFUNDED'], true)) {
            return response()->json([
                'received' => true,
                'processed' => false,
                'reason' => 'status_ignored',
                'mapped' => $mapped,
            ]);
        }

        $e2e = $this->extractEndToEndId($data);
        $paidAt = isset($data['paidAt']) && is_string($data['paidAt']) ? $data['paidAt'] : null;
        $beneficiary = is_array($data['beneficiary'] ?? null) ? $data['beneficiary'] : [];

        $raw = array_merge($data, [
            'endToEndId' => $e2e,
            'destinationName' => $data['destinationName'] ?? $beneficiary['name'] ?? null,
            'destinationDocument' => $data['destinationDocument'] ?? $beneficiary['document'] ?? null,
            'message' => $data['refusedReason'] ?? $data['message'] ?? null,
        ]);

        $updated = app(FluxPaymentsCashOutOutcomeService::class)->applyFinalStatusIfNeeded(
            $payout,
            $mapped,
            $raw,
            $e2e,
            $paidAt
        );

        Log::info('[FLUXPAYMENTS][WEBHOOK] Status do saque processado', [
            'payout_id' => $payout->id,
            'event' => $event,
            'new_status' => $mapped,
            'updated' => $updated,
        ]);

        return response()->json(['received' => true, 'processed' => $updated]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapCashInEvent(string $event, array $data): string
    {
        return match ($event) {
            'transaction.paid' => 'PAID_OUT',
            'transaction.refunded', 'transaction.partially_refunded', 'transaction.chargeback' => 'REFUNDED',
            'transaction.cancelled', 'transaction.expired' => 'CANCELED',
            'transaction.failed' => 'FAILED',
            default => app(FluxPaymentsPixAcquirerService::class)->mapChargeStatus(
                (string) ($data['status'] ?? ''),
                $data
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapCashOutEvent(string $event, array $data): string
    {
        return match ($event) {
            'transfer.paid' => 'COMPLETED',
            'transfer.failed' => 'FAILED',
            'transfer.cancelled', 'transfer.rejected' => 'CANCELLED',
            'transfer.refunded', 'transfer.partially_refunded' => 'REFUNDED',
            default => app(FluxPaymentsPixAcquirerService::class)->mapPayoutStatus(
                (string) ($data['status'] ?? 'PROCESSING')
            ),
        };
    }

    private function inferEventFromStatus(string $status, string $objectType): string
    {
        $s = strtolower(trim($status));
        $isTransfer = $objectType === 'transfer' || str_contains($s, 'transfer');

        return match (true) {
            in_array($s, ['paid', 'paid_out', 'completed', 'success'], true) => $isTransfer ? 'transfer.paid' : 'transaction.paid',
            in_array($s, ['failed', 'error', 'refused'], true) => $isTransfer ? 'transfer.failed' : 'transaction.failed',
            in_array($s, ['cancelled', 'canceled'], true) => $isTransfer ? 'transfer.cancelled' : 'transaction.cancelled',
            $s === 'expired' => 'transaction.expired',
            in_array($s, ['refunded'], true) => $isTransfer ? 'transfer.refunded' : 'transaction.refunded',
            in_array($s, ['partially_refunded'], true) => $isTransfer ? 'transfer.partially_refunded' : 'transaction.partially_refunded',
            $s === 'processing' => 'transfer.processing',
            $s === 'pending' => $isTransfer ? 'transfer.created' : 'transaction.pending',
            $s === 'pending_approval' => 'transfer.pending_approval',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractEndToEndId(array $data): ?string
    {
        $pix = is_array($data['pix'] ?? null) ? $data['pix'] : [];
        $candidates = [
            $data['pixEnd2EndId'] ?? null,
            $data['pixEndToEndId'] ?? null,
            $data['endToEndId'] ?? null,
            $data['end2EndId'] ?? null,
            $pix['end2EndId'] ?? null,
            $pix['endToEndId'] ?? null,
            $pix['end_to_end_id'] ?? null,
        ];

        foreach ($candidates as $v) {
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }

    /**
     * Cada nominal FluxPayments tem seu próprio webhook_secret — o evento
     * pode ter vindo de qualquer uma delas, então valida contra todas as
     * conhecidas (secret global do .env + credentials de cada nominal) e
     * aceita se alguma bater.
     */
    private function passesOptionalSignature(Request $request): bool
    {
        $secrets = $this->knownWebhookSecrets();
        if ($secrets === []) {
            return true;
        }

        $signature = (string) $request->header('x-webhook-signature', '');
        if ($signature === '') {
            return ! app()->environment('production');
        }

        $raw = $request->getContent();

        foreach ($secrets as $secret) {
            if (hash_equals(hash_hmac('sha256', $raw, $secret), $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function knownWebhookSecrets(): array
    {
        $secrets = [];

        $global = trim((string) config('fluxpayments.webhook_secret', ''));
        if ($global !== '') {
            $secrets[] = $global;
        }

        $nominais = Adquirente::where('provider', 'fluxpayments')->whereNotNull('credentials')->get();
        foreach ($nominais as $nominal) {
            $secret = trim((string) ($nominal->credentials['webhook_secret'] ?? ''));
            if ($secret !== '') {
                $secrets[] = $secret;
            }
        }

        return array_values(array_unique($secrets));
    }

    private function findDeposit(string $transactionId, string $externalRef): ?Solicitacoes
    {
        $base = Solicitacoes::query()->where('executor_ordem', 'fluxpayments');

        if ($transactionId !== '') {
            $found = (clone $base)->where('idTransaction', $transactionId)->first();
            if ($found !== null) {
                return $found;
            }
        }

        if ($externalRef !== '') {
            return (clone $base)->where(function ($q) use ($externalRef) {
                $q->where('idTransaction', $externalRef)
                    ->orWhere('externalreference', $externalRef);
            })->first();
        }

        return null;
    }

    private function findCashOut(string $transactionId, string $externalRef): ?SolicitacoesCashOut
    {
        $base = SolicitacoesCashOut::query()->where('executor_ordem', 'fluxpayments');

        if ($transactionId !== '') {
            $found = (clone $base)->where('idTransaction', $transactionId)->first();
            if ($found !== null) {
                return $found;
            }
        }

        if ($externalRef !== '') {
            return (clone $base)->where(function ($q) use ($externalRef) {
                $q->where('idTransaction', $externalRef)
                    ->orWhere('externalreference', $externalRef)
                    ->orWhere('descricao_externa', $externalRef)
                    ->orWhere('end_to_end', $externalRef);
            })->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function processPaid(Solicitacoes $deposit, array $data): JsonResponse
    {
        if ($deposit->status === 'PAID_OUT') {
            $this->creditPixDepositOrLog(Solicitacoes::find($deposit->id), false);

            return response()->json(['received' => true, 'processed' => false, 'reason' => 'already_paid']);
        }

        $e2e = $this->extractEndToEndId($data);
        $paidAt = $data['paidAt'] ?? $data['paid_at'] ?? null;

        $updated = DB::transaction(function () use ($deposit, $e2e) {
            $locked = Solicitacoes::where('id', $deposit->id)->lockForUpdate()->first();

            if (! $locked || $locked->status === 'PAID_OUT') {
                return false;
            }

            $updateData = ['status' => 'PAID_OUT'];
            if ($e2e !== null) {
                $updateData['end_to_end'] = $e2e;
            }

            $locked->update($updateData);

            return true;
        });

        if (! $updated) {
            return response()->json(['received' => true, 'processed' => false]);
        }

        Log::info('[FLUXPAYMENTS][WEBHOOK] Depósito confirmado', [
            'deposit_id' => $deposit->id,
            'transaction_id' => $deposit->idTransaction,
            'end_to_end' => $e2e,
        ]);

        $this->creditPixDepositOrLog(Solicitacoes::find($deposit->id));
        $this->dispatchClientWebhook($deposit, 'PAID_OUT', is_string($paidAt) ? $paidAt : null);

        return response()->json(['received' => true, 'processed' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function processRefunded(Solicitacoes $deposit, array $data): JsonResponse
    {
        if ($deposit->status === 'REFUNDED') {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'already_refunded']);
        }

        // MED em mediação + refund/chargeback → mesmo fluxo Treeal (CLOSED+AGREED)
        if (strtoupper((string) $deposit->status) === 'MEDIATION') {
            $settled = app(FluxPaymentsInfractionService::class)->settleIfInMediation($deposit, $data);
            if ($settled) {
                Helper::calculaSaldoLiquido($deposit->user_id);
                app(PaymentProcessingService::class)->invalidateCachesAfterPayment($deposit->user_id);
                $this->dispatchClientWebhook($deposit->fresh(), 'REFUNDED');

                return response()->json(['received' => true, 'processed' => true, 'reason' => 'med_settled']);
            }
        }

        $e2e = $this->extractEndToEndId($data);

        $updated = DB::transaction(function () use ($deposit, $e2e) {
            $locked = Solicitacoes::where('id', $deposit->id)->lockForUpdate()->first();

            if (! $locked || $locked->status === 'REFUNDED') {
                return false;
            }

            $updateData = ['status' => 'REFUNDED'];
            if ($e2e !== null) {
                $updateData['end_to_end'] = $e2e;
            }

            $locked->update($updateData);

            return true;
        });

        if (! $updated) {
            return response()->json(['received' => true, 'processed' => false]);
        }

        Log::info('[FLUXPAYMENTS][WEBHOOK] Depósito reembolsado', [
            'deposit_id' => $deposit->id,
            'transaction_id' => $deposit->idTransaction,
            'refundedAmount' => $data['refundedAmount'] ?? null,
            'event' => $data['_event'] ?? null,
        ]);

        Helper::calculaSaldoLiquido($deposit->user_id);
        app(PaymentProcessingService::class)->invalidateCachesAfterPayment($deposit->user_id);
        $this->dispatchClientWebhook($deposit, 'REFUNDED');

        return response()->json(['received' => true, 'processed' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function processCanceled(Solicitacoes $deposit, array $data, string $status = 'CANCELED'): JsonResponse
    {
        $terminal = ['PAID_OUT', 'COMPLETED', 'REFUNDED', 'CANCELED', 'FAILED'];
        if (in_array($deposit->status, $terminal, true)) {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'already_terminal']);
        }

        $statusToSet = $status === 'FAILED' ? 'FAILED' : 'CANCELED';

        $updated = DB::transaction(function () use ($deposit, $statusToSet, $terminal) {
            $locked = Solicitacoes::where('id', $deposit->id)->lockForUpdate()->first();

            if (! $locked || in_array($locked->status, $terminal, true)) {
                return false;
            }

            $locked->update(['status' => $statusToSet]);

            return true;
        });

        if (! $updated) {
            return response()->json(['received' => true, 'processed' => false]);
        }

        Log::info('[FLUXPAYMENTS][WEBHOOK] Cobrança encerrada sem pagamento', [
            'deposit_id' => $deposit->id,
            'transaction_id' => $deposit->idTransaction,
            'status' => $statusToSet,
            'event' => $data['_event'] ?? null,
            'refusedReason' => $data['refusedReason'] ?? null,
        ]);

        $this->dispatchClientWebhook($deposit, $statusToSet);

        return response()->json(['received' => true, 'processed' => true]);
    }

    private function creditPixDepositOrLog(?Solicitacoes $deposit, bool $rethrow = true): void
    {
        if (! $deposit) {
            return;
        }

        try {
            app(PaymentProcessingService::class)->processPaymentReceived($deposit);
        } catch (\Throwable $e) {
            Log::error('[FLUXPAYMENTS][WEBHOOK] Falha ao creditar depósito PIX', [
                'deposit_id' => $deposit->id,
                'transaction_id' => $deposit->idTransaction,
                'error' => $e->getMessage(),
            ]);
            if ($rethrow) {
                throw $e;
            }
        }
    }

    private function dispatchClientWebhook(
        Solicitacoes $record,
        string $status,
        ?string $paymentDate = null
    ): void {
        if (empty($record->callback) || $record->callback === 'web') {
            return;
        }

        $record->refresh();

        ClientWebhookDispatchJob::send(
            $record->callback,
            $record->idTransaction,
            $status,
            (float) $record->amount,
            $paymentDate ?? now()->toIso8601String(),
            ClientWebhookPayloadBuilder::extraForDeposit($record),
            WebhookClientMessages::getMessageForStatus($status, 'PIX_IN')
        );
    }
}
