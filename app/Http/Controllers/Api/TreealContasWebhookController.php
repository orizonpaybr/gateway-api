<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Helpers\WebhookClientMessages;
use App\Http\Controllers\Controller;
use App\Jobs\ClientWebhookDispatchJob;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\CashOut\CashOutOutcomeApplier;
use App\Services\ClientWebhookPayloadBuilder;
use App\Services\PaymentProcessingService;
use App\Services\Treeal\TreealPixAcquirerService;
use App\Services\TreealContas\TreealContasInfractionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhooks da API Contas TREEAL / ONZ ({type,data}).
 *
 * TRANSFER — cash-out liquidado/cancelado; CASHOUT — falha de validação;
 * REFUND — estorno de cash-out; RECEIVE — cash-in liquidado (fallback idempotente ao webhook QR).
 */
class TreealContasWebhookController extends Controller
{
    private const IGNORE_TYPES = [];

    /** Status de infração que mantêm o valor bloqueado (em mediação) no recebedor. */
    private const INFRACTION_ACTIVE_STATUSES = ['OPEN', 'ACKNOWLEDGED', 'WAITING_ADJUSTMENTS', 'WAITING_PSP', 'DEFENDED', 'ANSWERED'];

    public function handle(Request $request): JsonResponse
    {
        if (! $this->passesOptionalAuthHeader($request)) {
            Log::warning('[TREEAL_CONTAS][WEBHOOK] Header de autenticação inválido ou ausente', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['received' => false, 'error' => 'unauthorized'], 401);
        }

        $payload = $request->all();
        $type = strtoupper(trim((string) ($payload['type'] ?? '')));
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        Log::info('[TREEAL_CONTAS][WEBHOOK] Evento recebido', [
            'type' => $type,
            'status' => $data['status'] ?? null,
            'txId' => $data['txId'] ?? null,
            'endToEndId' => $data['endToEndId'] ?? null,
            'errorCode' => $data['errorCode'] ?? null,
            'rejectionReason' => $data['rejectionReason'] ?? null,
            'message' => $data['message'] ?? null,
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
            'INFRACTION' => $this->handleInfraction($data),
            default => response()->json(['received' => true, 'processed' => false, 'reason' => 'unknown_type']),
        };
    }

    /**
     * Infração Pix (MED). Quando aberta contra a conta recebedora, registra a infração,
     * bloqueia o valor (status MEDIATION no depósito) e notifica o integrador. Ao encerrar,
     * confirma a devolução (fraude) ou libera o bloqueio (defesa aceita / cancelada).
     *
     * @param  array<string, mixed>  $data
     */
    private function handleInfraction(array $data): JsonResponse
    {
        $infractionId = trim((string) ($data['id'] ?? ''));
        if ($infractionId === '') {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'missing_infraction_id']);
        }

        $status = strtoupper(trim((string) ($data['status'] ?? '')));
        $analysisResult = strtoupper(trim((string) ($data['analysisResult'] ?? '')));

        $endToEndId = trim((string) ($data['endToEndId'] ?? ''));
        // O payload do webhook traz transactionId, mas o endToEndId só vem no detalhe.
        if ($endToEndId === '') {
            $endToEndId = $this->resolveInfractionEndToEndId($infractionId, $data);
        }

        $matchType = 'none';
        $deposit = $this->findTreealDepositForInfraction($data, $endToEndId, $matchType);
        if (! $deposit) {
            Log::warning('[TREEAL_CONTAS][WEBHOOK][INFRACTION] Depósito não localizado', [
                'infraction_id' => $infractionId,
                'status' => $status,
                'end_to_end' => $endToEndId !== '' ? $endToEndId : null,
                'transactionId' => $data['transactionId'] ?? null,
            ]);

            return response()->json(['received' => true, 'processed' => false, 'reason' => 'deposit_not_found']);
        }

        $amount = $this->extractInfractionAmount($data, (float) $deposit->amount);

        $this->upsertInfractionRecord($deposit, $infractionId, $status, $analysisResult, $amount, $data, $endToEndId);

        $depositStatusChanged = $this->applyInfractionToDeposit($deposit, $status, $analysisResult);

        app(PaymentProcessingService::class)->invalidateInfractionCaches((string) $deposit->user_id);
        app(PaymentProcessingService::class)->invalidateCachesAfterPayment((string) $deposit->user_id);

        $this->dispatchInfractionClientWebhook($deposit->fresh(), $status, $amount);

        Log::info('[TREEAL_CONTAS][WEBHOOK][INFRACTION] Processada', [
            'infraction_id' => $infractionId,
            'status' => $status,
            'analysis_result' => $analysisResult !== '' ? $analysisResult : null,
            'deposit_id' => $deposit->id,
            'match_type' => $matchType,
            'deposit_status_changed' => $depositStatusChanged,
        ]);

        return response()->json(['received' => true, 'processed' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveInfractionEndToEndId(string $infractionId, array $data): string
    {
        $service = app(TreealContasInfractionService::class);
        if (! $service->isConfigured()) {
            return '';
        }

        try {
            $detail = $service->getInfraction($infractionId);
            if (($detail['success'] ?? false) && is_array($detail['raw'] ?? null)) {
                $detailData = is_array($detail['raw']['data'] ?? null) ? $detail['raw']['data'] : $detail['raw'];

                return trim((string) ($detailData['endToEndId'] ?? ''));
            }
        } catch (\Throwable $e) {
            Log::warning('[TREEAL_CONTAS][WEBHOOK][INFRACTION] Falha ao buscar detalhe', [
                'infraction_id' => $infractionId,
                'error' => $e->getMessage(),
            ]);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findTreealDepositForInfraction(array $data, string $endToEndId, string &$matchType = 'none'): ?Solicitacoes
    {
        $base = Solicitacoes::query()->where('executor_ordem', 'treeal');

        // 1. Match pelo endToEndId (campo mais preciso).
        if ($endToEndId !== '') {
            $found = (clone $base)->where('end_to_end', $endToEndId)->first();
            if ($found !== null) {
                $matchType = 'e2e';

                return $found;
            }
        }

        // 2. Match pelo transactionId da Treeal (pode ser nosso idTransaction,
        //    externalreference ou paymentcode dependendo do fluxo).
        $txId = trim((string) ($data['transactionId'] ?? $data['txId'] ?? ''));
        if ($txId !== '') {
            $found = (clone $base)->where(function ($q) use ($txId) {
                $q->where('idTransaction', $txId)
                  ->orWhere('externalreference', $txId)
                  ->orWhere('paymentcode', $txId);
            })->first();

            if ($found !== null) {
                $matchType = 'txid';

                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractInfractionAmount(array $data, float $fallback): float
    {
        $amount = $data['transactionAmount']['amount'] ?? null;
        if (is_numeric($amount) && (float) $amount > 0) {
            return round((float) $amount, 2);
        }

        return $fallback;
    }

    /**
     * Cria/atualiza o registro local da infração (idempotente pelo provider_infraction_id).
     *
     * @param  array<string, mixed>  $data
     */
    private function upsertInfractionRecord(
        Solicitacoes $deposit,
        string $infractionId,
        string $status,
        string $analysisResult,
        float $amount,
        array $data,
        string $endToEndId,
    ): void {
        $creation = $this->parseDate($data['creationDate'] ?? null) ?? Carbon::now();
        // A API Treeal não envia deadlineDate; prazo padrão MED = 5 dias após abertura.
        $limite = $this->parseDate($data['deadlineDate'] ?? null)
            ?? $creation->copy()->addDays(5);

        app(\App\Services\Infraction\InfractionEffectApplier::class)->upsert('treeal', $infractionId, [
            'user_id' => (string) $deposit->user_id,
            'transaction_id' => (string) ($deposit->idTransaction ?? ''),
            'status' => $this->mapInfractionStatusToLocal($status),
            'tipo' => strtolower((string) ($data['type'] ?? 'fraude')),
            'descricao' => mb_substr((string) ($data['reportDetails'] ?? 'Infração Pix (MED) registrada.'), 0, 1000),
            'valor' => $amount,
            'end_to_end' => $endToEndId !== '' ? $endToEndId : (string) ($deposit->end_to_end ?? ''),
            'data_criacao' => $creation,
            'data_limite' => $limite,
            'detalhes' => json_encode([
                'infractionId' => $infractionId,
                'status' => $status,
                'analysisResult' => $analysisResult !== '' ? $analysisResult : null,
                'reportedBy' => $data['reportedBy'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'analysis_result' => $analysisResult !== '' ? $analysisResult : null,
        ]);
    }

    /**
     * Aplica o efeito da infração ao depósito (bloqueio / devolução / liberação),
     * via {@see \App\Services\Infraction\InfractionEffectApplier} (compartilhado).
     */
    private function applyInfractionToDeposit(Solicitacoes $deposit, string $status, string $analysisResult): bool
    {
        $applier = app(\App\Services\Infraction\InfractionEffectApplier::class);

        if (in_array($status, self::INFRACTION_ACTIVE_STATUSES, true)) {
            return $applier->hold($deposit);
        }

        if ($status === 'CLOSED') {
            return $analysisResult === 'AGREED'
                ? $applier->refund($deposit)
                : $applier->release($deposit);
        }

        if ($status === 'CANCELLED') {
            return $applier->release($deposit);
        }

        return false;
    }

    private function mapInfractionStatusToLocal(string $status): string
    {
        return match ($status) {
            'OPEN' => 'PENDENTE',
            'ACKNOWLEDGED', 'WAITING_ADJUSTMENTS', 'WAITING_PSP', 'DEFENDED', 'ANSWERED' => 'EM_ANALISE',
            'CLOSED' => 'RESOLVIDA',
            'CANCELLED' => 'CANCELADA',
            default => 'PENDENTE',
        };
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function dispatchInfractionClientWebhook(?Solicitacoes $deposit, string $status, float $amount): void
    {
        if (! $deposit || empty($deposit->callback) || $deposit->callback === 'web') {
            return;
        }

        $clientStatus = match ($status) {
            'OPEN' => 'INFRACTION_OPEN',
            'ACKNOWLEDGED', 'WAITING_ADJUSTMENTS', 'DEFENDED', 'ANSWERED' => 'INFRACTION_ACKNOWLEDGED',
            'CLOSED' => 'INFRACTION_CLOSED',
            'CANCELLED' => 'INFRACTION_CANCELLED',
            default => 'INFRACTION_OPEN',
        };

        ClientWebhookDispatchJob::send(
            $deposit->callback,
            (string) $deposit->idTransaction,
            $clientStatus,
            $amount,
            now()->toIso8601String(),
            ClientWebhookPayloadBuilder::extraForDeposit($deposit),
            WebhookClientMessages::getMessageForStatus($clientStatus, 'PIX_IN'),
        );
    }

    /**
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

        if ($this->isInboundNonTerminalStatus($providerStatus)) {
            return response()->json(['received' => true, 'processed' => true, 'reason' => 'non_terminal_ack']);
        }

        Log::info('[TREEAL_CONTAS][WEBHOOK][RECEIVE] Status não mapeado para depósito', [
            'status' => $providerStatus,
        ]);

        return response()->json(['received' => true, 'processed' => false, 'reason' => 'receive_status_unhandled']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleTransfer(array $data): JsonResponse
    {
        $payout = $this->findTreealCashOut($data);
        if (! $payout) {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'payout_not_found']);
        }

        $treeal = app(TreealPixAcquirerService::class);
        $providerStatus = strtoupper(trim((string) ($data['status'] ?? '')));
        $mapped = $treeal->mapPayoutStatus($providerStatus !== '' ? $providerStatus : 'PROCESSING');

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
            '[TREEAL_CONTAS][OUTCOME]',
        );

        return response()->json(['received' => true, 'processed' => $updated]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleRefundCashOut(array $data): JsonResponse
    {
        $payout = $this->findTreealCashOut($data);
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
            '[TREEAL_CONTAS][OUTCOME]',
        );

        return response()->json(['received' => true, 'processed' => $updated]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleCashOutValidationFailure(array $data): JsonResponse
    {
        $payout = $this->findTreealCashOut($data);
        if (! $payout) {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'payout_not_found']);
        }

        $txn = is_array($data['transaction'] ?? null) ? $data['transaction'] : [];
        $rawForClient = array_merge($data, $txn);

        $e2e = trim((string) ($data['endToEndId'] ?? ''));
        $createdAt = isset($data['createdAt']) && is_string($data['createdAt']) ? $data['createdAt'] : null;

        Log::warning('[TREEAL_CONTAS][WEBHOOK][CASHOUT] Falha de validação DICT', [
            'payout_id' => $payout->id,
            'payout_status' => $payout->status,
            'end_to_end' => $e2e !== '' ? $e2e : null,
            'errorCode' => $rawForClient['errorCode'] ?? null,
            'rejectionReason' => $rawForClient['rejectionReason'] ?? null,
            'message' => $rawForClient['message'] ?? null,
        ]);

        $updated = app(CashOutOutcomeApplier::class)->applyValidationFailureIfNeeded(
            $payout,
            $rawForClient,
            $e2e !== '' ? $e2e : null,
            $createdAt,
            '[TREEAL_CONTAS][OUTCOME]',
        );

        return response()->json(['received' => true, 'processed' => $updated]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyDepositPaidFromContas(array $data): JsonResponse
    {
        $deposit = $this->findTreealDeposit($data);
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

        $endToEndId = trim((string) ($data['endToEndId'] ?? ''));
        $paidAt = isset($data['createdAt']) && is_string($data['createdAt']) ? $data['createdAt'] : null;

        $updated = DB::transaction(function () use ($deposit, $endToEndId) {
            $locked = Solicitacoes::where('id', $deposit->id)->lockForUpdate()->first();
            if (! $locked || in_array($locked->status, ['PAID_OUT', 'COMPLETED'], true)) {
                return false;
            }

            $updateData = ['status' => 'PAID_OUT'];
            if ($endToEndId !== '') {
                $updateData['end_to_end'] = $endToEndId;
            }

            $locked->update($updateData);

            return true;
        });

        if (! $updated) {
            return response()->json(['received' => true, 'processed' => false]);
        }

        try {
            app(PaymentProcessingService::class)->processPaymentReceived(Solicitacoes::findOrFail($deposit->id));
        } catch (\Throwable $e) {
            Log::error('[TREEAL_CONTAS][WEBHOOK] Falha ao creditar depósito (RECEIVE)', [
                'deposit_id' => $deposit->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->dispatchDepositClientWebhook($deposit->fresh(), 'PAID_OUT', $paidAt);

        return response()->json(['received' => true, 'processed' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyDepositRefundedFromContas(array $data, ?Solicitacoes $deposit = null): JsonResponse
    {
        $deposit ??= $this->findTreealDeposit($data);
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

        app(PaymentProcessingService::class)->invalidateCachesAfterPayment($deposit->user_id);
        Helper::calculaSaldoLiquido($deposit->user_id);
        $this->dispatchDepositClientWebhook($deposit->fresh(), 'REFUNDED', null);

        return response()->json(['received' => true, 'processed' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findTreealDeposit(array $data): ?Solicitacoes
    {
        $txId = trim((string) ($data['txId'] ?? ''));
        $e2e = trim((string) ($data['endToEndId'] ?? ''));

        $base = Solicitacoes::query()->where('executor_ordem', 'treeal');

        if ($txId !== '') {
            $found = (clone $base)->where('idTransaction', $txId)->first();
            if ($found !== null) {
                return $found;
            }
        }

        if ($e2e !== '') {
            return (clone $base)->where('end_to_end', $e2e)->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findTreealCashOut(array $data): ?SolicitacoesCashOut
    {
        $base = SolicitacoesCashOut::query()->where('executor_ordem', 'treeal');

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
        return in_array($status, ['REFUNDED', 'DEVOLVIDO', 'PARTIALLY_REFUNDED'], true);
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

    private function passesOptionalAuthHeader(Request $request): bool
    {
        $headerName = trim((string) config('treeal_contas.webhook_auth_header', ''));
        $expectedValue = (string) config('treeal_contas.webhook_auth_value', '');

        if ($headerName === '' || $expectedValue === '') {
            return true;
        }

        $received = $request->header($headerName);

        return is_string($received) && hash_equals($expectedValue, $received);
    }
}
