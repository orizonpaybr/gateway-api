<?php

namespace App\Services\CashOut;

use App\Helpers\Helper;
use App\Helpers\WebhookClientMessages;
use App\Jobs\ClientWebhookDispatchJob;
use App\Jobs\ReconcileFyhubCashOutBeneficiaryJob;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Services\AffiliateCommissionService;
use App\Services\ClientWebhookPayloadBuilder;
use App\Services\PaymentProcessingService;
use App\Services\WithdrawalFailureRefundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pipeline idempotente de status terminal de saque PIX (balanceamento, comissão, webhook ao cliente).
 * Compartilhado entre adquirentes.
 */
final class CashOutOutcomeApplier
{
    /** @var list<string> */
    public const TERMINAL_STATUSES = ['COMPLETED', 'FAILED', 'CANCELLED', 'REFUNDED'];

    public static function isTerminalStatus(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Envia postback ao integrador quando o saque já está em status terminal no banco
     * (ex.: recuperação manual ou webhook inbound após resposta síncrona da API).
     */
    public function notifyClientTerminalStatus(
        SolicitacoesCashOut $record,
        ?array $rawForClientMessage = null,
        ?string $paidAtIso = null,
        bool $forceWebhook = false,
    ): void {
        if (! self::isTerminalStatus((string) $record->status)) {
            return;
        }

        $this->dispatchCashOutClientWebhook($record, (string) $record->status, $rawForClientMessage, $paidAtIso, $forceWebhook);
    }

    /**
     * @param  array<string, mixed>|null  $rawForClientMessage  Payload do provedor para mensagem ao cliente
     * @return bool true se o status foi atualizado nesta chamada
     */
    public function applyTerminalStatusIfNeeded(
        SolicitacoesCashOut $payout,
        string $newStatus,
        ?array $rawForClientMessage = null,
        ?string $e2eToSet = null,
        ?string $paidAtIso = null,
        string $logTag = '[PIX_OUT][OUTCOME]',
    ): bool {
        if (! self::isTerminalStatus($newStatus)) {
            return false;
        }

        $updated = DB::transaction(function () use ($payout, $newStatus, $e2eToSet, $rawForClientMessage) {
            $locked = SolicitacoesCashOut::where('id', $payout->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return false;
            }

            if (in_array($locked->status, self::TERMINAL_STATUSES, true)) {
                return false;
            }

            $previousStatus = $locked->status;

            $updateData = ['status' => $newStatus];
            if ($e2eToSet !== null && $e2eToSet !== '') {
                $updateData['end_to_end'] = $e2eToSet;
            }

            $beneficiaryPatch = CashOutBeneficiaryResolver::patchForModel($rawForClientMessage);
            if ($beneficiaryPatch !== []) {
                $updateData = array_merge($updateData, $beneficiaryPatch);
            }

            $locked->update($updateData);

            WithdrawalFailureRefundService::creditBackIfApplicable(
                $locked->fresh(),
                $previousStatus,
                $newStatus
            );

            if ($newStatus === 'COMPLETED') {
                $childUser = User::where('user_id', $locked->user_id)->lockForUpdate()->first();
                if ($childUser) {
                    app(AffiliateCommissionService::class)->processCashOutCommission(
                        $locked->fresh(),
                        $childUser
                    );
                }
            }

            return true;
        });

        if (! $updated) {
            return false;
        }

        Log::info($logTag.' Status terminal aplicado', [
            'payout_id' => $payout->id,
            'transaction_id' => $payout->idTransaction,
            'new_status' => $newStatus,
        ]);

        $fresh = SolicitacoesCashOut::find($payout->id);
        if ($fresh === null) {
            return true;
        }

        Helper::calculaSaldoLiquido($fresh->user_id);
        app(PaymentProcessingService::class)->invalidateCachesAfterPayment($fresh->user_id);

        $this->dispatchCashOutClientWebhook($fresh, $newStatus, $rawForClientMessage, $paidAtIso);

        return true;
    }

    /**
     * Falha de validação DICT (webhook CASHOUT REJECTED): pode reverter COMPLETED prematuro.
     *
     * @param  array<string, mixed>|null  $rawForClientMessage
     */
    public function applyValidationFailureIfNeeded(
        SolicitacoesCashOut $payout,
        ?array $rawForClientMessage = null,
        ?string $e2eToSet = null,
        ?string $paidAtIso = null,
        string $logTag = '[PIX_OUT][VALIDATION_FAILURE]',
    ): bool {
        $current = SolicitacoesCashOut::find($payout->id);
        if (! $current) {
            return false;
        }

        if (in_array($current->status, ['FAILED', 'CANCELLED', 'REFUNDED'], true)) {
            return false;
        }

        if ($current->status === 'COMPLETED') {
            return $this->revertFalsePositiveCompletionToFailed(
                $current,
                $rawForClientMessage,
                $e2eToSet,
                $paidAtIso,
                $logTag,
            );
        }

        return $this->applyTerminalStatusIfNeeded(
            $current,
            'FAILED',
            $rawForClientMessage,
            $e2eToSet,
            $paidAtIso,
            $logTag,
        );
    }

    /**
     * @param  array<string, mixed>|null  $rawForClientMessage
     */
    private function revertFalsePositiveCompletionToFailed(
        SolicitacoesCashOut $payout,
        ?array $rawForClientMessage,
        ?string $e2eToSet,
        ?string $paidAtIso,
        string $logTag,
    ): bool {
        $updated = DB::transaction(function () use ($payout, $rawForClientMessage, $e2eToSet) {
            $locked = SolicitacoesCashOut::where('id', $payout->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status !== 'COMPLETED') {
                return false;
            }

            $updateData = ['status' => 'FAILED'];
            if ($e2eToSet !== null && $e2eToSet !== '') {
                $updateData['end_to_end'] = $e2eToSet;
            }

            $beneficiaryPatch = CashOutBeneficiaryResolver::patchForModel($rawForClientMessage);
            if ($beneficiaryPatch !== []) {
                $updateData = array_merge($updateData, $beneficiaryPatch);
            }

            $locked->update($updateData);

            WithdrawalFailureRefundService::creditBackAfterFalsePositiveCompletion($locked->fresh());

            try {
                app(AffiliateCommissionService::class)->reverseCashOutCommissionForFailedWithdrawal($locked->fresh());
            } catch (\Throwable $e) {
                Log::error($logTag.' Falha ao reverter comissão após COMPLETED prematuro', [
                    'payout_id' => $locked->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return true;
        });

        if (! $updated) {
            return false;
        }

        Log::warning($logTag.' COMPLETED prematuro revertido para FAILED', [
            'payout_id' => $payout->id,
            'transaction_id' => $payout->idTransaction,
        ]);

        $fresh = SolicitacoesCashOut::find($payout->id);
        if ($fresh === null) {
            return true;
        }

        Helper::calculaSaldoLiquido($fresh->user_id);
        app(PaymentProcessingService::class)->invalidateCachesAfterPayment($fresh->user_id);
        $this->dispatchCashOutClientWebhook($fresh, 'FAILED', $rawForClientMessage, $paidAtIso);

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $rawForClientMessage
     */
    private function dispatchCashOutClientWebhook(
        SolicitacoesCashOut $record,
        string $status,
        ?array $rawForClientMessage,
        ?string $paidAtIso,
        bool $forceWebhook = false,
    ): void {
        $callbackUrl = CashOutClientCallbackResolver::resolve($record);
        if ($callbackUrl === null) {
            return;
        }

        $record->refresh();

        if (! $forceWebhook && $this->shouldDeferFyhubCashOutWebhookUntilBeneficiary($record, $status)) {
            // Roda logo após enviar a resposta HTTP (não depende de queue worker).
            ReconcileFyhubCashOutBeneficiaryJob::dispatchAfterResponse($record->id);

            Log::info('[FYHUB][BENEFICIARY] Postback adiado (poll afterResponse)', [
                'payout_id' => $record->id,
                'transaction_id' => $record->idTransaction,
            ]);

            return;
        }

        $rawForWebhook = $rawForClientMessage;

        $payloadForReason = self::normalizeRawForPixMessage($rawForWebhook);
        $message = WebhookClientMessages::getMessageForStatus($status, 'PIX_OUT', $payloadForReason === [] ? null : $payloadForReason);

        ClientWebhookDispatchJob::send(
            $callbackUrl,
            $record->idTransaction,
            $status,
            (float) $record->amount,
            $paidAtIso ?? now()->toIso8601String(),
            ClientWebhookPayloadBuilder::extraForCashOut($record, $rawForWebhook),
            $message
        );
    }

    /**
     * COMPLETED FyHub sem nome do recebedor: um único postback sai pelo job quando o GET trouxer creditorAccount.
     */
    private function shouldDeferFyhubCashOutWebhookUntilBeneficiary(SolicitacoesCashOut $record, string $status): bool
    {
        return $record->executor_ordem === 'fyhub'
            && $status === 'COMPLETED'
            && trim((string) $record->beneficiaryname) === '';
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<string, mixed>
     */
    public static function normalizeRawForPixMessage(?array $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        $out = [];

        if (! empty($raw['erro_descriptor'])) {
            $out['message'] = is_string($raw['erro_descriptor'])
                ? $raw['erro_descriptor']
                : (string) $raw['erro_descriptor'];
        }

        foreach (['errorCode', 'rejectionReason', 'code', 'message'] as $k) {
            if (! isset($raw[$k])) {
                continue;
            }
            $v = $raw[$k];
            if (is_string($v) || is_numeric($v)) {
                $out[$k] = is_string($v) ? $v : (string) $v;
            }
        }

        return $out;
    }
}
