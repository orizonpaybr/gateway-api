<?php

namespace App\Jobs;

use App\Helpers\Helper;
use App\Helpers\WebhookClientMessages;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\BalanceService;
use App\Services\PaymentEventService;
use App\Services\PaymentProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MagenPay → Coratri (solicitações, saldo, eventos) → cliente final (URL em callback / postback).
 *
 * Eventos: pixRequestIn, pixRequestOut, pixReversalIn, pixReversalOut.
 */
class ProcessMagenPayWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAGEN_EXECUTOR = 'magenpay';

    private const MAGEN_ADQUIRENTE_REF = 'Magen';

    public int $tries = 5;

    /** @var array<int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(
        private int $webhookLogId
    ) {}

    public function handle(
        PaymentProcessingService $paymentProcessing,
        BalanceService $balanceService,
        PaymentEventService $eventService
    ): void {
        $log = WebhookLog::query()->find($this->webhookLogId);
        if ($log === null) {
            return;
        }

        $payload = $log->payload;
        if (! is_array($payload)) {
            $log->update(['status' => 'FAILED', 'error' => 'Payload inválido']);

            return;
        }

        $type = $payload['type'] ?? null;
        $data = $payload['data'] ?? null;
        if (! is_string($type) || ! is_array($data)) {
            $log->update(['status' => 'FAILED', 'error' => 'Envelope MagenPay inválido']);

            return;
        }

        try {
            match ($type) {
                'pixRequestIn' => $this->handlePixRequestIn($data, $paymentProcessing),
                'pixRequestOut' => $this->handlePixRequestOut($data, $paymentProcessing, $balanceService),
                'pixReversalIn' => $this->handlePixReversalIn($data, $balanceService, $eventService, $paymentProcessing),
                'pixReversalOut' => $this->handlePixReversalOut($data),
                default => Log::warning('ProcessMagenPayWebhookJob — tipo desconhecido', ['type' => $type]),
            };
        } catch (\Throwable $e) {
            Log::error('ProcessMagenPayWebhookJob — falha no handler', [
                'type' => $type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $log->update(['status' => 'FAILED', 'error' => $e->getMessage()]);
            throw $e;
        }

        $log->update(['status' => 'PROCESSED', 'error' => null]);
    }

    /**
     * Cash-in: confirma PIX recebido → credita via {@see PaymentProcessingService::processPaymentReceived}
     * e notifica o cliente (callback), se houver URL.
     *
     * @param  array<string, mixed>  $data
     */
    private function handlePixRequestIn(array $data, PaymentProcessingService $paymentProcessing): void
    {
        Log::info('MagenPay pixRequestIn', $this->pixRequestLogContext($data));

        $deposit = $this->findMagenDeposit($data);
        if ($deposit === null) {
            Log::warning('ProcessMagenPayWebhookJob — pixRequestIn sem solicitação Coratri', $this->pixRequestLogContext($data));

            return;
        }

        $e2e = $data['endToEndId'] ?? $data['endToEndid'] ?? null;
        if (is_string($e2e) && $e2e !== '' && $deposit->end_to_end !== $e2e) {
            $deposit->update(['end_to_end' => $e2e]);
            $deposit->refresh();
        }

        $statusRaw = strtolower((string) ($data['status'] ?? ''));

        if ($this->isMagenSuccessStatus($statusRaw)) {
            if (in_array($deposit->status, ['PAID_OUT', 'COMPLETED'], true)) {
                if ($deposit->webhook_status !== 'delivered') {
                    $this->dispatchClientWebhookDeposit($deposit, 'PAID_OUT', $data);
                }

                return;
            }

            $paymentProcessing->processPaymentReceived($deposit);
            $deposit->refresh();
            $this->dispatchClientWebhookDeposit($deposit, 'PAID_OUT', $data);
            $paymentProcessing->invalidateCachesAfterPayment($deposit->user_id);

            return;
        }

        if ($this->isMagenFailedStatus($statusRaw)) {
            if (! in_array($deposit->status, ['PAID_OUT', 'COMPLETED'], true)) {
                $deposit->update(['status' => 'CANCELLED']);
            }
            $this->dispatchClientWebhookDeposit($deposit, 'FAILED', $data);
            $paymentProcessing->invalidateCachesAfterPayment($deposit->user_id);

            return;
        }

        Log::info('ProcessMagenPayWebhookJob — pixRequestIn ignorado (status intermediário)', [
            'status' => $statusRaw,
            'idTransaction' => $deposit->idTransaction,
        ]);
    }

    /**
     * Cash-out: liquida ou falha o saque → finaliza saque / estorna valor+taxa e notifica o cliente.
     *
     * @param  array<string, mixed>  $data
     */
    private function handlePixRequestOut(
        array $data,
        PaymentProcessingService $paymentProcessing,
        BalanceService $balanceService
    ): void {
        $ctx = $this->pixRequestLogContext($data);
        if (($data['status'] ?? '') === 'failed') {
            $ctx['error'] = $data['error'] ?? null;
            Log::warning('MagenPay pixRequestOut — falha', $ctx);
        } else {
            Log::info('MagenPay pixRequestOut', $ctx);
        }

        $withdrawal = $this->findMagenWithdrawal($data);
        if ($withdrawal === null) {
            Log::warning('ProcessMagenPayWebhookJob — pixRequestOut sem solicitação Coratri', $ctx);

            return;
        }

        $e2e = $data['endToEndId'] ?? $data['endToEndid'] ?? null;
        if (is_string($e2e) && $e2e !== '' && $withdrawal->end_to_end !== $e2e) {
            $withdrawal->update(['end_to_end' => $e2e]);
            $withdrawal->refresh();
        }

        $statusRaw = strtolower((string) ($data['status'] ?? ''));

        if ($this->isMagenSuccessStatus($statusRaw)) {
            if (in_array($withdrawal->status, ['COMPLETED', 'PAID_OUT'], true)) {
                if ($withdrawal->webhook_status !== 'delivered') {
                    $this->dispatchClientWebhookCashOut($withdrawal, 'PAID_OUT', $data);
                }

                return;
            }

            $paymentProcessing->processWithdrawal($withdrawal);
            $withdrawal->refresh();
            $this->dispatchClientWebhookCashOut($withdrawal, 'PAID_OUT', $data);
            $paymentProcessing->invalidateCachesAfterPayment($withdrawal->user_id);

            return;
        }

        if ($this->isMagenFailedStatus($statusRaw)) {
            $this->refundFailedWithdrawal($withdrawal, $balanceService, $paymentProcessing, $data);
            $this->dispatchClientWebhookCashOut($withdrawal->fresh(), 'FAILED', $data);
            $paymentProcessing->invalidateCachesAfterPayment($withdrawal->user_id);

            return;
        }

        Log::info('ProcessMagenPayWebhookJob — pixRequestOut ignorado (status intermediário)', [
            'status' => $statusRaw,
            'idTransaction' => $withdrawal->idTransaction,
        ]);
    }

    /**
     * Estorno de entrada: devolve o crédito do Pix recebido (Magen) e notifica o cliente.
     *
     * @param  array<string, mixed>  $data
     */
    private function handlePixReversalIn(
        array $data,
        BalanceService $balanceService,
        PaymentEventService $eventService,
        PaymentProcessingService $paymentProcessing
    ): void {
        $meta = $this->metadataArray($data);
        $ctx = $this->reversalLogContext($data, $meta);
        Log::info('MagenPay pixReversalIn', $ctx);

        $trigger = isset($meta['triggerTransactionId']) ? trim((string) $meta['triggerTransactionId']) : '';
        if ($trigger === '') {
            Log::warning('ProcessMagenPayWebhookJob — pixReversalIn sem triggerTransactionId', $ctx);

            return;
        }

        $deposit = Solicitacoes::query()
            ->where(function ($q) {
                $q->where('executor_ordem', self::MAGEN_EXECUTOR)
                    ->orWhere('adquirente_ref', self::MAGEN_ADQUIRENTE_REF);
            })
            ->where(function ($q) use ($trigger) {
                $q->where('end_to_end', $trigger)
                    ->orWhere('idTransaction', $trigger)
                    ->orWhere('externalreference', $trigger);
            })
            ->first();

        if ($deposit === null) {
            Log::warning('ProcessMagenPayWebhookJob — pixReversalIn: depósito original não encontrado', $ctx);

            return;
        }

        if ($deposit->status === 'REFUNDED') {
            Log::info('ProcessMagenPayWebhookJob — pixReversalIn já aplicado (idempotência)', [
                'idTransaction' => $deposit->idTransaction,
            ]);

            return;
        }

        if (! in_array($deposit->status, ['PAID_OUT', 'COMPLETED'], true)) {
            Log::warning('ProcessMagenPayWebhookJob — pixReversalIn: depósito não estava pago', [
                'idTransaction' => $deposit->idTransaction,
                'status' => $deposit->status,
            ]);

            return;
        }

        $e2e = $data['endToEndId'] ?? $data['endToEndid'] ?? null;
        if (is_string($e2e) && $e2e !== '') {
            $deposit->update(['end_to_end' => $e2e]);
        }

        $liquido = (float) ($deposit->deposito_liquido ?? 0);
        $amountWebhook = isset($data['amount']) ? (float) $data['amount'] : 0.0;
        $debit = $amountWebhook > 0 ? min($liquido, $amountWebhook) : $liquido;
        if ($debit <= 0) {
            Log::warning('ProcessMagenPayWebhookJob — pixReversalIn: valor de estorno inválido', $ctx);

            return;
        }

        $reason = isset($meta['reason']) ? (string) $meta['reason'] : 'magen_reversal';

        $user = User::where('user_id', $deposit->user_id)->first();
        if ($user === null) {
            Log::error('ProcessMagenPayWebhookJob — pixReversalIn: usuário não encontrado', [
                'user_id' => $deposit->user_id,
            ]);

            return;
        }

        DB::transaction(function () use ($deposit, $user, $balanceService, $eventService, $debit, $reason) {
            $depositLocked = Solicitacoes::where('id', $deposit->id)->lockForUpdate()->first();
            if ($depositLocked === null) {
                return;
            }
            if ($depositLocked->status === 'REFUNDED') {
                return;
            }

            $balanceBefore = (float) $user->saldo;
            $balanceService->decrementBalanceForRefund($user, $debit, 'saldo');
            $userFresh = $user->fresh();
            $balanceAfter = $userFresh !== null ? (float) $userFresh->saldo : $balanceBefore;

            $depositLocked->update(['status' => 'REFUNDED']);

            $eventService->recordPaymentReversed($depositLocked, $userFresh ?? $user, $balanceBefore, $balanceAfter, $reason);
        });

        Helper::calculaSaldoLiquido($deposit->user_id);
        $deposit->refresh();

        $this->dispatchClientWebhookDeposit($deposit, 'REFUNDED', $data);
        $paymentProcessing->invalidateCachesAfterPayment($deposit->user_id);
    }

    /**
     * Estorno de saída (iniciado na Coratri): auditoria; confirmação para fluxo futuro do dashboard.
     *
     * @param  array<string, mixed>  $data
     */
    private function handlePixReversalOut(array $data): void
    {
        $meta = $this->metadataArray($data);
        $ctx = $this->reversalLogContext($data, $meta);

        if (($data['status'] ?? '') === 'failed') {
            $ctx['error'] = $data['error'] ?? null;
            Log::warning('MagenPay pixReversalOut — falha', $ctx);
        } else {
            Log::info('MagenPay pixReversalOut — concluído', $ctx);
        }

        // Quando existir tela de estorno no dashboard, vincular por externalId / correlationId aqui.
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findMagenDeposit(array $data): ?Solicitacoes
    {
        $ids = $this->collectMagenCorrelationIds($data);

        foreach ($ids as $id) {
            $found = Solicitacoes::query()
                ->where(function ($q) {
                    $q->where('executor_ordem', self::MAGEN_EXECUTOR)
                        ->orWhere('adquirente_ref', self::MAGEN_ADQUIRENTE_REF);
                })
                ->where(function ($q) use ($id) {
                    $q->where('idTransaction', $id)
                        ->orWhere('externalreference', $id)
                        ->orWhere('end_to_end', $id);
                })
                ->first();
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findMagenWithdrawal(array $data): ?SolicitacoesCashOut
    {
        $ids = $this->collectMagenCorrelationIds($data);

        foreach ($ids as $id) {
            $found = SolicitacoesCashOut::query()
                ->where('executor_ordem', self::MAGEN_EXECUTOR)
                ->where(function ($q) use ($id) {
                    $q->where('idTransaction', $id)
                        ->orWhere('externalreference', $id)
                        ->orWhere('end_to_end', $id)
                        ->orWhere('descricao_externa', $id);
                })
                ->first();
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function collectMagenCorrelationIds(array $data): array
    {
        $raw = [];
        foreach (['txId', 'externalId', 'endToEndId', 'endToEndid'] as $key) {
            if (! isset($data[$key])) {
                continue;
            }
            $v = trim((string) $data[$key]);
            if ($v !== '') {
                $raw[] = $v;
            }
        }

        return array_values(array_unique($raw));
    }

    private function isMagenSuccessStatus(string $statusRaw): bool
    {
        return in_array($statusRaw, ['success', 'completed', 'paid', 'confirmed'], true);
    }

    private function isMagenFailedStatus(string $statusRaw): bool
    {
        return in_array($statusRaw, ['failed', 'error', 'rejected', 'cancelled', 'canceled'], true);
    }

    private function refundFailedWithdrawal(
        SolicitacoesCashOut $withdrawal,
        BalanceService $balanceService,
        PaymentProcessingService $paymentProcessing,
        array $data
    ): void {
        DB::transaction(function () use ($withdrawal, $balanceService) {
            $w = SolicitacoesCashOut::where('id', $withdrawal->id)->lockForUpdate()->first();
            if ($w === null) {
                return;
            }
            if (in_array($w->status, ['FAILED', 'CANCELLED'], true)) {
                return;
            }
            if (in_array($w->status, ['COMPLETED', 'PAID_OUT'], true)) {
                Log::warning('ProcessMagenPayWebhookJob — falha Pix Out mas saque já concluído no sistema', [
                    'idTransaction' => $w->idTransaction,
                    'status' => $w->status,
                ]);

                return;
            }

            $user = User::where('user_id', $w->user_id)->lockForUpdate()->first();
            if ($user === null) {
                return;
            }

            $valorDevolver = (float) $w->amount + (float) ($w->taxa_cash_out ?? 0);
            if ($valorDevolver > 0) {
                $balanceService->incrementBalance($user, $valorDevolver, 'saldo');
            }

            $w->update([
                'status' => 'FAILED',
                'end_to_end' => $data['endToEndId'] ?? $data['endToEndid'] ?? $w->end_to_end,
            ]);
        });

        Helper::calculaSaldoLiquido($withdrawal->user_id);
    }

    /**
     * @param  array<string, mixed>  $payloadForReason
     */
    private function dispatchClientWebhookDeposit(Solicitacoes $deposit, string $status, array $payloadForReason = []): void
    {
        $url = trim((string) ($deposit->callback ?? ''));
        if ($url === '' || $url === 'web') {
            return;
        }

        $extra = ['typeTransaction' => 'PIX_IN'];
        if (! empty($payloadForReason['debtor']) && is_array($payloadForReason['debtor'])) {
            $extra['payer'] = $payloadForReason['debtor'];
        }

        $message = WebhookClientMessages::getMessageForStatus($status, 'PIX_IN', $payloadForReason);

        ClientWebhookDispatchJob::dispatch(
            $url,
            (string) $deposit->idTransaction,
            $status,
            (float) ($deposit->deposito_liquido ?? $deposit->amount ?? 0),
            $payloadForReason['finishedAt'] ?? now()->toIso8601String(),
            $extra,
            $message
        );
    }

    /**
     * @param  array<string, mixed>  $payloadForReason
     */
    private function dispatchClientWebhookCashOut(SolicitacoesCashOut $w, string $status, array $payloadForReason = []): void
    {
        $url = trim((string) ($w->callback ?? ''));
        if ($url === '' || $url === 'web') {
            return;
        }

        $extra = ['typeTransaction' => 'PIX_OUT'];
        if (! empty($payloadForReason['creditor']) && is_array($payloadForReason['creditor'])) {
            $extra['beneficiary'] = $payloadForReason['creditor'];
        }

        $message = WebhookClientMessages::getMessageForStatus($status, 'PIX_OUT', $payloadForReason);

        ClientWebhookDispatchJob::dispatch(
            $url,
            (string) $w->idTransaction,
            $status,
            (float) ($w->amount ?? 0),
            $payloadForReason['finishedAt'] ?? now()->toIso8601String(),
            array_merge($extra, ['sender' => ['user_id' => $w->user_id]]),
            $message
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function pixRequestLogContext(array $data): array
    {
        return [
            'endToEndId' => $data['endToEndId'] ?? $data['endToEndid'] ?? null,
            'externalId' => $data['externalId'] ?? null,
            'txId' => $data['txId'] ?? null,
            'flow' => $data['flow'] ?? null,
            'amount' => $data['amount'] ?? null,
            'status' => $data['status'] ?? null,
            'transactionType' => $data['transactionType'] ?? null,
            'method' => $data['method'] ?? null,
            'finishedAt' => $data['finishedAt'] ?? null,
            'receiverKeyId' => $data['receiverKeyId'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function reversalLogContext(array $data, array $meta): array
    {
        return [
            'endToEndId' => $data['endToEndId'] ?? $data['endToEndid'] ?? null,
            'externalId' => $data['externalId'] ?? null,
            'txId' => $data['txId'] ?? null,
            'flow' => $data['flow'] ?? null,
            'amount' => $data['amount'] ?? null,
            'status' => $data['status'] ?? null,
            'transactionType' => $data['transactionType'] ?? null,
            'description' => $data['description'] ?? null,
            'finishedAt' => $data['finishedAt'] ?? null,
            'reason' => $meta['reason'] ?? null,
            'triggerTransactionId' => $meta['triggerTransactionId'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function metadataArray(array $data): array
    {
        $m = $data['metadata'] ?? null;

        return is_array($m) ? $m : [];
    }

    public function failed(?\Throwable $e): void
    {
        WebhookLog::query()->where('id', $this->webhookLogId)->update([
            'status' => 'FAILED',
            'error' => $e !== null ? $e->getMessage() : 'unknown',
        ]);
    }
}
