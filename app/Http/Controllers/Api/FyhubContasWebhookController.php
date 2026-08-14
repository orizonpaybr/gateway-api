<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Helpers\WebhookClientMessages;
use App\Http\Controllers\Controller;
use App\Jobs\ClientWebhookDispatchJob;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Services\CashOut\CashOutOutcomeApplier;
use App\Services\ClientWebhookPayloadBuilder;
use App\Services\Fyhub\FyhubDepositReconciler;
use App\Services\Fyhub\FyhubPixAcquirerService;
use App\Services\PaymentProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhooks da API Contas FYHUB ({type,data}) — espelha o que já fazemos com Simpay:
 * cash-in: liquidação (PAID_OUT); cash-out: COMPLETED / FAILED / CANCELLED / REFUNDED; falha de validação (CASHOUT).
 *
 * Depósitos continuam podendo chegar pelo webhook da API QR Codes ({@see FyhubWebhookController});
 * este endpoint cobre RECEIVE da Contas quando configurado (idempotente se ambos dispararem).
 */
class FyhubContasWebhookController extends Controller
{
    private const IGNORE_TYPES = ['INFRACTION'];

    /**
     * Valida o secret do webhook (opt-in). A API Contas reenvia os headers
     * definidos no cadastro do webhook — configure o mesmo valor em
     * fyhub_contas.webhook_secret e no header do cadastro. Sem secret
     * configurado, mantém o comportamento aberto (retrocompatível).
     */
    private function webhookAuthorized(Request $request): bool
    {
        $secret = trim((string) config('fyhub_contas.webhook_secret', ''));
        if ($secret === '') {
            return true;
        }

        $header = (string) config('fyhub_contas.webhook_secret_header', 'X-Webhook-Token');
        $sent = trim((string) $request->header($header, ''));

        return $sent !== '' && hash_equals($secret, $sent);
    }

    public function handle(Request $request): JsonResponse
    {
        if (! $this->webhookAuthorized($request)) {
            Log::warning('[FYHUB_CONTAS][WEBHOOK] Rejeitado: secret inválido', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['received' => true, 'processed' => false, 'reason' => 'unauthorized'], 401);
        }

        $payload = $request->all();
        $type = strtoupper(trim((string) ($payload['type'] ?? '')));
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        Log::info('[FYHUB_CONTAS][WEBHOOK] Evento recebido', [
            'type' => $type,
            'status' => $data['status'] ?? null,
            'txId' => $data['txId'] ?? null,
            'endToEndId' => $data['endToEndId'] ?? null,
        ]);

        if ($type === '' || $data === []) {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'invalid_payload']);
        }

        if (in_array($type, self::IGNORE_TYPES, true)) {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'type_not_handled']);
        }

        return match ($type) {
            'RECEIVE' => $this->handleReceive($data),
            'TRANSFER' => $this->handleTransfer($data),
            'REFUND' => $this->handleRefundCashOut($data),
            'CASHOUT' => $this->handleCashOutValidationFailure($data),
            default => response()->json(['received' => true, 'processed' => false, 'reason' => 'unknown_type']),
        };
    }

    /**
     * Cash-in liquidado na Contas (equivalente ao PAID do Simpay).
     *
     * @param  array<string, mixed>  $data
     */
    private function handleReceive(array $data): JsonResponse
    {
        $providerStatus = strtoupper(trim((string) ($data['status'] ?? '')));

        if ($this->isInboundRefundedStatus($providerStatus)) {
            return $this->applyDepositRefundedFromContas($data);
        }

        if ($this->isInboundPaidStatus($providerStatus)) {
            return $this->applyDepositPaidFromContas($data);
        }

        // Pendente / intermediário: ACK para não penalizar retries da FYHUB
        if ($this->isInboundNonTerminalStatus($providerStatus)) {
            return response()->json(['received' => true, 'processed' => true, 'reason' => 'non_terminal_ack']);
        }

        Log::info('[FYHUB_CONTAS][WEBHOOK][RECEIVE] Status não mapeado para depósito', [
            'status' => $providerStatus,
        ]);

        return response()->json(['received' => true, 'processed' => false, 'reason' => 'receive_status_unhandled']);
    }

    /**
     * Cash-out liquidado ou cancelado (TRANSFER).
     *
     * @param  array<string, mixed>  $data
     */
    private function handleTransfer(array $data): JsonResponse
    {
        $payout = $this->findFyhubCashOut($data);
        if (! $payout) {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'payout_not_found']);
        }

        $fyhub = app(FyhubPixAcquirerService::class);
        $providerStatus = strtoupper(trim((string) ($data['status'] ?? '')));
        $mapped = $fyhub->mapPayoutStatus($providerStatus !== '' ? $providerStatus : 'PROCESSING');

        if (in_array($mapped, ['PROCESSING', 'PENDING'], true)) {
            return response()->json(['received' => true, 'processed' => true, 'reason' => 'non_terminal_ack']);
        }

        $terminalStatuses = ['COMPLETED', 'FAILED', 'CANCELLED', 'REFUNDED'];
        if (! in_array($mapped, $terminalStatuses, true)) {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'transfer_status_unhandled']);
        }

        if (in_array($payout->status, $terminalStatuses, true)) {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'already_terminal']);
        }

        $e2e = trim((string) ($data['endToEndId'] ?? ''));
        $paidAt = isset($data['createdAt']) && is_string($data['createdAt']) ? $data['createdAt'] : null;

        $updated = app(CashOutOutcomeApplier::class)->applyTerminalStatusIfNeeded(
            $payout,
            $mapped,
            $data,
            $e2e !== '' ? $e2e : null,
            $paidAt,
            '[FYHUB_CONTAS][OUTCOME]',
        );

        return response()->json([
            'received' => true,
            'processed' => $updated,
        ]);
    }

    /**
     * Estorno de cash-out (equivalente PIX_CASHOUT_REFUND Simpay).
     *
     * @param  array<string, mixed>  $data
     */
    private function handleRefundCashOut(array $data): JsonResponse
    {
        $payout = $this->findFyhubCashOut($data);
        if (! $payout) {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'payout_not_found']);
        }

        if (in_array($payout->status, ['COMPLETED', 'FAILED', 'CANCELLED', 'REFUNDED'], true)) {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'already_terminal']);
        }

        $e2e = trim((string) ($data['endToEndId'] ?? ''));
        $paidAt = isset($data['createdAt']) && is_string($data['createdAt']) ? $data['createdAt'] : null;

        $updated = app(CashOutOutcomeApplier::class)->applyTerminalStatusIfNeeded(
            $payout,
            'REFUNDED',
            $data,
            $e2e !== '' ? $e2e : null,
            $paidAt,
            '[FYHUB_CONTAS][OUTCOME]',
        );

        return response()->json(['received' => true, 'processed' => $updated]);
    }

    /**
     * Falha de validação no cash-out antes/durante envio (equivalente a erro Simpay).
     *
     * @param  array<string, mixed>  $data
     */
    private function handleCashOutValidationFailure(array $data): JsonResponse
    {
        $payout = $this->findFyhubCashOut($data);
        if (! $payout) {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'payout_not_found']);
        }

        $txn = is_array($data['transaction'] ?? null) ? $data['transaction'] : [];
        $rawForClient = array_merge($data, $txn);

        $e2e = trim((string) ($data['endToEndId'] ?? ''));
        $createdAt = isset($data['createdAt']) && is_string($data['createdAt']) ? $data['createdAt'] : null;

        $updated = app(CashOutOutcomeApplier::class)->applyValidationFailureIfNeeded(
            $payout,
            $rawForClient,
            $e2e !== '' ? $e2e : null,
            $createdAt,
            '[FYHUB_CONTAS][OUTCOME]',
        );

        return response()->json(['received' => true, 'processed' => $updated]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyDepositPaidFromContas(array $data): JsonResponse
    {
        $deposit = $this->findFyhubDeposit($data);
        if (! $deposit) {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'deposit_not_found']);
        }

        $devolution = $this->extractRefundEntryFromData($data);
        if ($devolution !== null && $this->refundEntryIsDevolvido($devolution)) {
            return $this->applyDepositRefundedFromContas($data, $deposit);
        }

        if (in_array($deposit->status, ['PAID_OUT', 'COMPLETED'], true)) {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'already_paid']);
        }

        // Segurança: RECEIVE não credita pelo payload. Re-consulta a cobrança na API
        // (getChargeStatus) e credita só quando a fyhub confirma PAID — bloqueia
        // webhook falso de cash-in. O reconciler faz crédito + postback.
        $confirmed = app(FyhubDepositReconciler::class)->reconcileIfPaid($deposit);

        if (! $confirmed) {
            return response()->json([
                'received' => true,
                'processed' => false,
                'reason' => 'not_confirmed_by_api',
            ]);
        }

        return response()->json(['received' => true, 'processed' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyDepositRefundedFromContas(array $data, ?Solicitacoes $deposit = null): JsonResponse
    {
        $deposit ??= $this->findFyhubDeposit($data);
        if (! $deposit) {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'deposit_not_found']);
        }

        if ($deposit->status === 'REFUNDED') {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'already_refunded']);
        }

        $endToEndId = trim((string) ($data['endToEndId'] ?? ''));

        $updated = DB::transaction(function () use ($deposit, $endToEndId) {
            $locked = Solicitacoes::where('id', $deposit->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'REFUNDED') {
                return false;
            }

            $updateData = ['status' => 'REFUNDED'];
            if ($endToEndId !== '') {
                $updateData['end_to_end'] = $endToEndId;
            }

            $locked->update($updateData);

            return true;
        });

        if (! $updated) {
            return response()->json(['received' => true, 'processed' => false]);
        }

        Log::info('[FYHUB_CONTAS][WEBHOOK] Depósito marcado como estornado (RECEIVE/refunds)', [
            'deposit_id' => $deposit->id,
            'txId' => $data['txId'] ?? null,
        ]);

        app(PaymentProcessingService::class)->invalidateCachesAfterPayment($deposit->user_id);
        Helper::calculaSaldoLiquido($deposit->user_id);
        $this->dispatchDepositClientWebhook($deposit->fresh(), 'REFUNDED', null);

        return response()->json(['received' => true, 'processed' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findFyhubDeposit(array $data): ?Solicitacoes
    {
        $txId = trim((string) ($data['txId'] ?? ''));
        $e2e = trim((string) ($data['endToEndId'] ?? ''));

        $base = Solicitacoes::query()->where('executor_ordem', 'fyhub');

        if ($txId !== '') {
            $found = (clone $base)->where('idTransaction', $txId)->first();
            if ($found !== null) {
                return $found;
            }
        }

        if ($e2e !== '') {
            $found = (clone $base)->where('end_to_end', $e2e)->first();
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findFyhubCashOut(array $data): ?SolicitacoesCashOut
    {
        $base = SolicitacoesCashOut::query()->where('executor_ordem', 'fyhub');

        $candidates = [];
        foreach (['txId', 'endToEndId', 'idempotencyKey'] as $key) {
            if (! isset($data[$key])) {
                continue;
            }
            $v = $data[$key];
            if (is_string($v) && trim($v) !== '') {
                $candidates[] = trim($v);
            } elseif (is_numeric($v)) {
                $candidates[] = trim((string) $v);
            }
        }

        foreach (array_unique($candidates) as $c) {
            if ($c === '') {
                continue;
            }

            $found = (clone $base)->where(function ($q) use ($c) {
                $q->where('idTransaction', $c)
                    ->orWhere('externalreference', $c)
                    ->orWhere('descricao_externa', $c)
                    ->orWhere('end_to_end', $c);
            })->first();

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function isInboundPaidStatus(string $status): bool
    {
        return in_array($status, ['LIQUIDATED', 'SUCCESS', 'PAID', 'COMPLETED', 'CONFIRMED', 'SETTLED'], true);
    }

    private function isInboundRefundedStatus(string $status): bool
    {
        return in_array($status, ['REFUNDED', 'DEVOLVIDO'], true);
    }

    private function isInboundNonTerminalStatus(string $status): bool
    {
        return $status === ''
            || in_array($status, [
                'PROCESSING', 'PENDING', 'SUBMITTED', 'QUEUED', 'NEW', 'CREATED', 'AWAITING',
            ], true);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function extractRefundEntryFromData(array $data): ?array
    {
        $refunds = $data['refunds'] ?? null;
        if (! is_array($refunds) || $refunds === []) {
            return null;
        }

        $first = $refunds[0] ?? null;

        return is_array($first) ? $first : null;
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function refundEntryIsDevolvido(array $entry): bool
    {
        $s = strtoupper(trim((string) ($entry['status'] ?? '')));

        return in_array($s, ['DEVOLVIDO', 'REFUNDED'], true);
    }

    private function dispatchDepositClientWebhook(
        Solicitacoes $deposit,
        string $status,
        ?string $paymentDate,
    ): void {
        if (empty($deposit->callback) || $deposit->callback === 'web') {
            return;
        }

        ClientWebhookDispatchJob::send(
            $deposit->callback,
            $deposit->idTransaction,
            $status,
            (float) $deposit->amount,
            is_string($paymentDate) && $paymentDate !== '' ? $paymentDate : now()->toIso8601String(),
            ClientWebhookPayloadBuilder::extraForDeposit($deposit),
            WebhookClientMessages::getMessageForStatus($status, 'PIX_IN')
        );
    }
}
