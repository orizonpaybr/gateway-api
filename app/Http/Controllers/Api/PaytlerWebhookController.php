<?php

namespace App\Http\Controllers\Api;

use App\Helpers\Helper;
use App\Helpers\WebhookClientMessages;
use App\Http\Controllers\Controller;
use App\Jobs\ClientWebhookDispatchJob;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Services\ClientWebhookPayloadBuilder;
use App\Services\PaymentProcessingService;
use App\Services\Paytler\PaytlerCashOutOutcomeService;
use App\Services\Paytler\PaytlerPixAcquirerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhook inbound da Paytler. Payload:
 * { event, status, transaction:{transactionId,uuid,externalId,amount,type}, bankData:{endtoendId,...}, error }
 *
 * Auth: HTTP Basic (Authorization: Basic base64(user:pass)) definido no cadastro,
 * com fallback por IP de origem. Espelha {@see SimpayWebhookController}.
 */
class PaytlerWebhookController extends Controller
{
    private const CASH_IN_EVENTS = ['PIX_PAY_IN'];
    private const CASH_OUT_EVENTS = ['PIX_PAY_OUT'];
    private const REFUND_EVENTS = ['PIX_REVERSAL', 'PIX_REVERSAL_OUT', 'PIX_REFUND'];
    private const MED_EVENTS = ['INFRACTION'];

    /**
     * Aceita se: (1) Basic auth bate com webhook_username/password, OU (2) IP na allowlist.
     * Sem nenhuma trava configurada = aberto (retrocompatível).
     */
    private function webhookAuthorized(Request $request): bool
    {
        $user = trim((string) config('paytler.webhook_username', ''));
        $pass = trim((string) config('paytler.webhook_password', ''));
        $allowedIps = array_filter(array_map('trim', explode(',', (string) config('paytler.webhook_allowed_ips', ''))));

        // 1) HTTP Basic.
        if ($user !== '' || $pass !== '') {
            $sentUser = (string) $request->getUser();
            $sentPass = (string) $request->getPassword();
            // Alguns proxies não populam PHP_AUTH_*; decodifica o header manualmente.
            if ($sentUser === '' && $sentPass === '') {
                $header = (string) $request->header('Authorization', '');
                if (stripos($header, 'Basic ') === 0) {
                    $decoded = base64_decode(substr($header, 6), true);
                    if ($decoded !== false && str_contains($decoded, ':')) {
                        [$sentUser, $sentPass] = explode(':', $decoded, 2);
                    }
                }
            }
            if (hash_equals($user, $sentUser) && hash_equals($pass, $sentPass)) {
                return true;
            }
        }

        // 2) IP de origem confiável.
        if ($allowedIps !== [] && in_array($request->ip(), $allowedIps, true)) {
            return true;
        }

        // 3) Nenhuma trava configurada = aberto.
        return $user === '' && $pass === '' && $allowedIps === [];
    }

    public function handle(Request $request): JsonResponse
    {
        if (! $this->webhookAuthorized($request)) {
            Log::warning('[PAYTLER][WEBHOOK] Rejeitado: autenticação inválida', [
                'ip' => $request->ip(),
                'has_auth_header' => $request->hasHeader('Authorization'),
            ]);

            return response()->json(['received' => true, 'processed' => false, 'reason' => 'unauthorized'], 401);
        }

        $payload = $request->all();
        $event = strtoupper((string) ($payload['event'] ?? ''));
        $status = strtoupper((string) ($payload['status'] ?? ''));
        $transaction = is_array($payload['transaction'] ?? null) ? $payload['transaction'] : [];
        $bankData = is_array($payload['bankData'] ?? null) ? $payload['bankData'] : [];

        Log::info('[PAYTLER][WEBHOOK] Evento recebido', [
            'event' => $event,
            'status' => $status,
            'transaction_id' => $transaction['transactionId'] ?? null,
            'external_id' => $transaction['externalId'] ?? null,
        ]);

        if ($event === '') {
            return response()->json(['received' => true, 'processed' => false]);
        }

        if (in_array($event, self::MED_EVENTS, true)) {
            return $this->handleMed($payload, $transaction, $bankData);
        }

        if (in_array($event, self::CASH_IN_EVENTS, true)) {
            return $this->handleCashIn($status, $transaction, $bankData);
        }

        if (in_array($event, self::REFUND_EVENTS, true)) {
            return $this->handleCashInRefund($transaction, $bankData);
        }

        if (in_array($event, self::CASH_OUT_EVENTS, true)) {
            return $this->handleCashOut($status, $transaction, $bankData);
        }

        Log::info('[PAYTLER][WEBHOOK] Evento ignorado', ['event' => $event]);

        return response()->json(['received' => true, 'processed' => false]);
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function findDeposit(array $transaction): ?Solicitacoes
    {
        $base = Solicitacoes::query()->where('executor_ordem', 'paytler');

        $uuid = trim((string) ($transaction['uuid'] ?? ''));
        if ($uuid !== '') {
            $found = (clone $base)->where('idTransaction', $uuid)->first();
            if ($found !== null) {
                return $found;
            }
        }
        $extId = trim((string) ($transaction['externalId'] ?? ''));
        if ($extId !== '') {
            $found = (clone $base)->where('externalreference', $extId)->first();
            if ($found !== null) {
                return $found;
            }
        }
        $tid = trim((string) ($transaction['transactionId'] ?? ''));
        if ($tid !== '') {
            return (clone $base)->where('idTransaction', $tid)->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @param  array<string, mixed>  $bankData
     */
    private function handleCashIn(string $status, array $transaction, array $bankData): JsonResponse
    {
        $deposit = $this->findDeposit($transaction);
        if (! $deposit) {
            Log::warning('[PAYTLER][WEBHOOK] Depósito não encontrado', [
                'external_id' => $transaction['externalId'] ?? null,
                'uuid' => $transaction['uuid'] ?? null,
            ]);

            return response()->json(['received' => true, 'processed' => false, 'reason' => 'deposit_not_found']);
        }

        $e2e = trim((string) ($bankData['endtoendId'] ?? ''));
        // transactionId = id do PAGAMENTO na Paytler (compartilhado entre charges do
        // mesmo pagamento). Chave de dedup — ver PaytlerCashInService.
        $txid = trim((string) ($transaction['transactionId'] ?? ''));

        if ($status === 'COMPLETED') {
            return $this->creditDeposit($deposit, $txid, $e2e);
        }
        if ($status === 'REFUNDED') {
            return $this->refundDepositRecord($deposit, $e2e);
        }

        Log::info('[PAYTLER][WEBHOOK] Cash in status não-terminal ignorado', ['status' => $status]);

        return response()->json(['received' => true, 'processed' => false]);
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @param  array<string, mixed>  $bankData
     */
    private function handleCashInRefund(array $transaction, array $bankData): JsonResponse
    {
        $deposit = $this->findDeposit($transaction);
        if (! $deposit) {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'deposit_not_found']);
        }

        return $this->refundDepositRecord($deposit, trim((string) ($bankData['endtoendId'] ?? '')));
    }

    private function creditDeposit(Solicitacoes $deposit, string $txid, string $e2e): JsonResponse
    {
        if ($deposit->status === 'PAID_OUT') {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'already_paid']);
        }

        // Crédito com dedup por pagamento (txid): um pagamento credita no máximo 1 depósito.
        $outcome = app(\App\Services\Paytler\PaytlerCashInService::class)
            ->creditIfNotDuplicate($deposit, $txid, $e2e !== '' ? $e2e : null);

        if ($outcome === 'duplicate') {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'duplicate_payment']);
        }
        if ($outcome !== 'credited') {
            return response()->json(['received' => true, 'processed' => false]);
        }

        $this->dispatchClientWebhook($deposit, 'PAID_OUT', 'PIX_IN');

        Log::info('[PAYTLER][WEBHOOK] Depósito confirmado', [
            'deposit_id' => $deposit->id,
            'txid' => $txid,
            'end_to_end' => $e2e,
        ]);

        return response()->json(['received' => true, 'processed' => true]);
    }

    private function refundDepositRecord(Solicitacoes $deposit, string $e2e): JsonResponse
    {
        if ($deposit->status === 'REFUNDED') {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'already_refunded']);
        }

        $updated = DB::transaction(function () use ($deposit, $e2e) {
            $locked = Solicitacoes::where('id', $deposit->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'REFUNDED') {
                return false;
            }
            $data = ['status' => 'REFUNDED'];
            if ($e2e !== '') {
                $data['end_to_end'] = $e2e;
            }
            $locked->update($data);

            return true;
        });

        if (! $updated) {
            return response()->json(['received' => true, 'processed' => false]);
        }

        Helper::calculaSaldoLiquido($deposit->user_id);
        app(PaymentProcessingService::class)->invalidateCachesAfterPayment($deposit->user_id);
        $this->dispatchClientWebhook($deposit, 'REFUNDED', 'PIX_IN');

        Log::info('[PAYTLER][WEBHOOK] Depósito reembolsado', ['deposit_id' => $deposit->id]);

        return response()->json(['received' => true, 'processed' => true]);
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @param  array<string, mixed>  $bankData
     */
    private function handleCashOut(string $status, array $transaction, array $bankData): JsonResponse
    {
        $payout = $this->findPayout($transaction);
        if (! $payout) {
            Log::warning('[PAYTLER][WEBHOOK] Saque não encontrado', [
                'transaction_id' => $transaction['transactionId'] ?? null,
                'external_id' => $transaction['externalId'] ?? null,
            ]);

            return response()->json(['received' => true, 'processed' => false, 'reason' => 'payout_not_found']);
        }

        $newStatus = app(PaytlerPixAcquirerService::class)->mapPayoutStatus($status);
        $terminal = ['COMPLETED', 'FAILED', 'CANCELLED', 'REFUNDED'];
        if (! in_array($newStatus, $terminal, true)) {
            return response()->json(['received' => true, 'processed' => true]); // ack informativo
        }

        if (in_array($payout->status, $terminal, true)) {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'already_terminal']);
        }

        $e2e = trim((string) ($bankData['endtoendId'] ?? ''));

        $updated = app(PaytlerCashOutOutcomeService::class)->applyFinalStatusIfNeeded(
            $payout,
            $newStatus,
            $transaction,
            $e2e !== '' ? $e2e : null,
            null,
        );

        if (! $updated) {
            return response()->json(['received' => true, 'processed' => false]);
        }

        Log::info('[PAYTLER][WEBHOOK] Status do saque atualizado', [
            'payout_id' => $payout->id,
            'new_status' => $newStatus,
        ]);

        return response()->json(['received' => true, 'processed' => true]);
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function findPayout(array $transaction): ?SolicitacoesCashOut
    {
        $base = SolicitacoesCashOut::query()->where('executor_ordem', 'paytler');

        foreach (['transactionId', 'uuid'] as $k) {
            $v = trim((string) ($transaction[$k] ?? ''));
            if ($v !== '') {
                $found = (clone $base)->where('idTransaction', $v)->first();
                if ($found !== null) {
                    return $found;
                }
            }
        }
        $extId = trim((string) ($transaction['externalId'] ?? ''));
        if ($extId !== '') {
            return (clone $base)->where(function ($q) use ($extId) {
                $q->where('externalreference', $extId)->orWhere('descricao_externa', $extId);
            })->first();
        }

        return null;
    }

    /**
     * MED (infração): registra/atualiza pix_infracoes e aplica efeito no depósito.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $transaction
     * @param  array<string, mixed>  $bankData
     */
    private function handleMed(array $payload, array $transaction, array $bankData): JsonResponse
    {
        $med = is_array($payload['med'] ?? null) ? $payload['med'] : $payload;
        $medId = trim((string) ($med['uuid'] ?? $med['infractionId'] ?? $med['id'] ?? ''));
        if ($medId === '') {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'missing_med_id']);
        }

        $e2e = trim((string) ($med['endtoend'] ?? $bankData['endtoendId'] ?? $transaction['endtoendId'] ?? ''));
        $deposit = $this->findDepositForMed($e2e, $transaction);
        if (! $deposit) {
            Log::warning('[PAYTLER][WEBHOOK][MED] depósito não encontrado', ['med_id' => $medId, 'e2e' => $e2e]);

            return response()->json(['received' => true, 'processed' => false, 'reason' => 'deposit_not_found']);
        }

        $medStatus = strtoupper((string) ($med['status'] ?? $payload['status'] ?? ''));
        $amount = is_numeric($med['amount'] ?? null) ? round((float) $med['amount'], 2) : (float) $deposit->amount;
        $creation = Carbon::now();

        $applier = app(\App\Services\Infraction\InfractionEffectApplier::class);
        $applier->upsert('paytler', $medId, [
            'user_id' => (string) $deposit->user_id,
            'transaction_id' => (string) ($deposit->idTransaction ?? ''),
            'status' => $this->mapMedStatusToLocal($medStatus),
            'tipo' => 'fraude',
            'descricao' => mb_substr((string) ($med['details'] ?? 'Infração Pix (MED) PAYTLER registrada.'), 0, 1000),
            'valor' => $amount,
            'end_to_end' => $e2e !== '' ? $e2e : (string) ($deposit->end_to_end ?? ''),
            'data_criacao' => $creation,
            'data_limite' => $creation->copy()->addDays(5),
            'detalhes' => json_encode([
                'medId' => $medId,
                'status' => $medStatus,
                'dataMed' => $med['dataMed'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        // Aberta/em análise bloqueia; aceita/devolvida estorna; cancelada/rejeitada libera.
        match ($this->mapMedStatusToLocal($medStatus)) {
            'PENDENTE', 'EM_ANALISE' => $applier->hold($deposit),
            'RESOLVIDA' => in_array($medStatus, ['ACCEPTED', 'REFUNDED', 'COMPLETED'], true)
                ? $applier->refund($deposit)
                : $applier->release($deposit),
            default => $applier->release($deposit),
        };

        Log::info('[PAYTLER][WEBHOOK][MED] registrado', ['med_id' => $medId, 'deposit_id' => $deposit->id]);

        return response()->json(['received' => true, 'processed' => true]);
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function findDepositForMed(string $e2e, array $transaction): ?Solicitacoes
    {
        $base = Solicitacoes::query()->where('executor_ordem', 'paytler');
        if ($e2e !== '') {
            $found = (clone $base)->where('end_to_end', $e2e)->first();
            if ($found !== null) {
                return $found;
            }
        }

        return $this->findDeposit($transaction);
    }

    private function mapMedStatusToLocal(string $status): string
    {
        return match (strtoupper($status)) {
            'WAITING', 'OPEN', 'CREATED', 'PENDING' => 'PENDENTE',
            'ANALYSIS', 'IN_ANALYSIS', 'ANALYSING' => 'EM_ANALISE',
            'ACCEPTED', 'REFUNDED', 'COMPLETED', 'REJECTED', 'CLOSED' => 'RESOLVIDA',
            'CANCELLED', 'CANCELED' => 'CANCELADA',
            default => 'PENDENTE',
        };
    }

    private function dispatchClientWebhook(
        Solicitacoes|SolicitacoesCashOut $record,
        string $status,
        string $typeTransaction,
        ?string $paymentDate = null
    ): void {
        if (empty($record->callback) || $record->callback === 'web') {
            return;
        }

        $record->refresh();
        $message = WebhookClientMessages::getMessageForStatus($status, $typeTransaction);
        $extra = $record instanceof Solicitacoes
            ? ClientWebhookPayloadBuilder::extraForDeposit($record)
            : ClientWebhookPayloadBuilder::extraForCashOut($record);

        ClientWebhookDispatchJob::send(
            $record->callback,
            $record->idTransaction,
            $status,
            (float) $record->amount,
            $paymentDate ?? now()->toIso8601String(),
            $extra,
            $message
        );
    }
}
