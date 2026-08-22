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
use App\Services\Simpay\SimpayCashOutOutcomeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SimpayWebhookController extends Controller
{
    private const CASH_IN_TYPES = [
        'QR_CODE_COPY_AND_PASTE_PAID',
        'QR_CODE_COPY_AND_PASTE_REFUNDED',
        'QR_CODE_COPY_AND_PASTE_REFUNDED_ERROR',
    ];

    private const CASH_OUT_TYPES = [
        'PIX_CASHOUT_SUCCESS',
        'PIX_CASHOUT_ERROR',
        'PIX_CASHOUT_CANCELED',
        'PIX_CASHOUT_REFUND',
    ];

    private const MED_TYPES = [
        'MED_CREATED',
        'MED_APPROVED',
        'MED_REJECTED',
        'MED_CANCELLED',
    ];

    /**
     * Eventos cash-out apenas informativos: saque já está PROCESSING no gateway após a API síncrona.
     * Responder processed=true evita reenvios desnecessários pelo provedor.
     */
    private const CASH_OUT_ACK_ONLY_TYPES = [
        'PIX_CASHOUT_CREATED',
    ];

    /**
     * Valida o webhook pelo authorization_token que a SIMPAY reenvia no header
     * (definido no cadastro do webhook). Opt-in: sem token configurado, mantém
     * aberto (retrocompatível). Aceita o valor cru ou no formato "Bearer <token>".
     */
    private function webhookAuthorized(Request $request): bool
    {
        $token = trim((string) config('simpay.webhook_authorization_token', ''));
        if ($token === '') {
            return true;
        }

        $header = (string) config('simpay.webhook_authorization_header', 'Authorization');
        $sent = trim((string) $request->header($header, ''));
        $sent = (string) preg_replace('/^Bearer\s+/i', '', $sent);

        return $sent !== '' && hash_equals($token, $sent);
    }

    public function handle(Request $request): JsonResponse
    {
        if (! $this->webhookAuthorized($request)) {
            Log::warning('[SIMPAY][WEBHOOK] Rejeitado: authorization_token inválido', [
                'ip' => $request->ip(),
                'header' => (string) config('simpay.webhook_authorization_header', 'Authorization'),
                'header_presente' => $request->hasHeader((string) config('simpay.webhook_authorization_header', 'Authorization')),
            ]);

            return response()->json(['received' => true, 'processed' => false, 'reason' => 'unauthorized'], 401);
        }

        $payload = $request->all();
        $type = $payload['type'] ?? null;
        $data = $payload['data'] ?? [];

        Log::info('[SIMPAY][WEBHOOK] Evento recebido', [
            'type' => $type,
            'qr_code_id' => $data['qr_code_id'] ?? null,
            'transaction_id' => $data['transaction_id'] ?? null,
            'status' => $data['status'] ?? null,
        ]);

        if (empty($type) || empty($data)) {
            return response()->json(['received' => true, 'processed' => false]);
        }

        if (in_array($type, self::CASH_IN_TYPES, true)) {
            return $this->handleCashIn($type, $data);
        }

        if (in_array($type, self::CASH_OUT_ACK_ONLY_TYPES, true)) {
            Log::info('[SIMPAY][WEBHOOK] Cash out informativo (sem mudança de status)', [
                'type' => $type,
                'transaction_id' => $data['transaction_id'] ?? null,
                'tag' => $data['tag'] ?? null,
            ]);

            return response()->json(['received' => true, 'processed' => true]);
        }

        if (in_array($type, self::CASH_OUT_TYPES, true)) {
            return $this->handleCashOut($type, $data);
        }

        if (in_array($type, self::MED_TYPES, true)) {
            return $this->handleMed($type, $data);
        }

        Log::info('[SIMPAY][WEBHOOK] Tipo de evento ignorado', ['type' => $type]);

        return response()->json(['received' => true, 'processed' => false]);
    }

    /**
     * Localiza o saque Simpay pelo transaction_id do webhook e fallbacks (tag = correlation da API,
     * externalreference / descricao_externa gravados no create). Evita não processar cancelamento
     * quando o provedor envia outro identificador no payload.
     *
     * @param  array<string, mixed>  $data
     */
    private function findCashOutBySimpayWebhookIds(string $transactionId, array $data): ?SolicitacoesCashOut
    {
        $base = SolicitacoesCashOut::query()->where('executor_ordem', 'simpay');

        $tid = trim($transactionId);
        if ($tid !== '') {
            $found = (clone $base)->where('idTransaction', $tid)->first();
            if ($found !== null) {
                return $found;
            }
        }

        $candidates = [];
        foreach (['tag', 'code_transaction', 'correlation_id', 'external_id'] as $key) {
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
                    ->orWhere('descricao_externa', $c);
            })->first();
            if ($found !== null) {
                Log::info('[SIMPAY][WEBHOOK] Saque resolvido por fallback de id', [
                    'matched_by' => $c,
                    'payout_id' => $found->id,
                ]);

                return $found;
            }
        }

        return null;
    }

    /**
     * End-to-end conforme payload Simpay (PIX_CASHOUT_SUCCESS: end_to_end; legado/alternativo: operationUuid, code_transaction tipo E...).
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveCashOutEndToEnd(array $data): ?string
    {
        $candidates = [
            $data['end_to_end'] ?? null,
            $data['operationUuid'] ?? null,
            $data['operation_uuid'] ?? null,
            $data['endToEndId'] ?? null,
        ];

        foreach ($candidates as $v) {
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        $code = $data['code_transaction'] ?? null;
        if (is_string($code) && $code !== '' && str_starts_with($code, 'E')) {
            return $code;
        }

        return null;
    }

    private function handleCashIn(string $type, array $data): JsonResponse
    {
        $qrCodeId = (string) ($data['qr_code_id'] ?? '');

        if ($qrCodeId === '') {
            Log::warning('[SIMPAY][WEBHOOK] Cash in sem qr_code_id', ['type' => $type]);
            return response()->json(['received' => true, 'processed' => false]);
        }

        $deposit = Solicitacoes::where('idTransaction', $qrCodeId)
            ->where('executor_ordem', 'simpay')
            ->first();

        if (! $deposit) {
            Log::warning('[SIMPAY][WEBHOOK] Depósito não encontrado', [
                'type' => $type,
                'qr_code_id' => $qrCodeId,
            ]);
            return response()->json(['received' => true, 'processed' => false]);
        }

        return match ($type) {
            'QR_CODE_COPY_AND_PASTE_PAID' => $this->processCashInPaid($deposit, $data),
            'QR_CODE_COPY_AND_PASTE_REFUNDED' => $this->processCashInRefunded($deposit, $data),
            'QR_CODE_COPY_AND_PASTE_REFUNDED_ERROR' => $this->processCashInRefundError($deposit, $data),
            default => response()->json(['received' => true, 'processed' => false]),
        };
    }

    private function processCashInPaid(Solicitacoes $deposit, array $data): JsonResponse
    {
        if ($deposit->status === 'PAID_OUT') {
            $this->creditPixDepositOrLog(Solicitacoes::find($deposit->id), false);

            return response()->json(['received' => true, 'processed' => false, 'reason' => 'already_paid']);
        }

        $e2e = $data['end_to_end'] ?? null;
        $paymentDate = $data['payment_date'] ?? null;

        $updated = DB::transaction(function () use ($deposit, $e2e) {
            $locked = Solicitacoes::where('id', $deposit->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status === 'PAID_OUT') {
                return false;
            }

            $updateData = ['status' => 'PAID_OUT'];
            if ($e2e !== null && $e2e !== '') {
                $updateData['end_to_end'] = $e2e;
            }

            $locked->update($updateData);
            return true;
        });

        if (! $updated) {
            return response()->json(['received' => true, 'processed' => false]);
        }

        Log::info('[SIMPAY][WEBHOOK] Depósito confirmado', [
            'deposit_id' => $deposit->id,
            'transaction_id' => $deposit->idTransaction,
            'amount' => $deposit->amount,
            'end_to_end' => $e2e,
        ]);

        $this->creditPixDepositOrLog(Solicitacoes::find($deposit->id));

        $this->dispatchClientWebhook(
            $deposit,
            'PAID_OUT',
            'PIX_IN',
            $paymentDate
        );

        return response()->json(['received' => true, 'processed' => true]);
    }

    /**
     * Incremento de saldo em depósitos PIX Simpay só ocorre aqui (via processPaymentReceived).
     * Helper::calculaSaldoLiquido não altera users.saldo — apenas valor_saque_pendente.
     *
     * @param  bool  $rethrow  Se false, apenas loga (ex.: webhook duplicado já PAID_OUT).
     */
    private function creditPixDepositOrLog(?Solicitacoes $deposit, bool $rethrow = true): void
    {
        if (! $deposit) {
            return;
        }

        try {
            app(PaymentProcessingService::class)->processPaymentReceived($deposit);
        } catch (\Throwable $e) {
            Log::error('[SIMPAY][WEBHOOK] Falha ao creditar depósito PIX', [
                'deposit_id' => $deposit->id,
                'transaction_id' => $deposit->idTransaction,
                'user_id' => $deposit->user_id,
                'error' => $e->getMessage(),
            ]);
            if ($rethrow) {
                throw $e;
            }
        }
    }

    private function processCashInRefunded(Solicitacoes $deposit, array $data): JsonResponse
    {
        if ($deposit->status === 'REFUNDED') {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'already_refunded']);
        }

        $e2e = $data['end_to_end'] ?? null;

        $updated = DB::transaction(function () use ($deposit, $e2e) {
            $locked = Solicitacoes::where('id', $deposit->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status === 'REFUNDED') {
                return false;
            }

            $updateData = ['status' => 'REFUNDED'];
            if ($e2e !== null && $e2e !== '') {
                $updateData['end_to_end'] = $e2e;
            }

            $locked->update($updateData);
            return true;
        });

        if (! $updated) {
            return response()->json(['received' => true, 'processed' => false]);
        }

        Log::info('[SIMPAY][WEBHOOK] Depósito reembolsado', [
            'deposit_id' => $deposit->id,
            'transaction_id' => $deposit->idTransaction,
            'amount_chargeback' => $data['amount_chargeback'] ?? null,
        ]);

        Helper::calculaSaldoLiquido($deposit->user_id);
        app(\App\Services\PaymentProcessingService::class)
            ->invalidateCachesAfterPayment($deposit->user_id);

        $this->dispatchClientWebhook($deposit, 'REFUNDED', 'PIX_IN');

        return response()->json(['received' => true, 'processed' => true]);
    }

    private function processCashInRefundError(Solicitacoes $deposit, array $data): JsonResponse
    {
        Log::warning('[SIMPAY][WEBHOOK] Erro no reembolso do depósito', [
            'deposit_id' => $deposit->id,
            'transaction_id' => $deposit->idTransaction,
            'status' => $data['status'] ?? null,
            'chargeback_id' => $data['chargeback_id'] ?? null,
        ]);

        return response()->json(['received' => true, 'processed' => true]);
    }

    private function handleCashOut(string $type, array $data): JsonResponse
    {
        $transactionId = (string) ($data['transaction_id'] ?? '');

        if ($transactionId === '') {
            Log::warning('[SIMPAY][WEBHOOK] Cash out sem transaction_id', ['type' => $type]);
            return response()->json(['received' => true, 'processed' => false]);
        }

        $payout = $this->findCashOutBySimpayWebhookIds($transactionId, $data);

        if (! $payout) {
            Log::warning('[SIMPAY][WEBHOOK] Saque não encontrado', [
                'type' => $type,
                'transaction_id' => $transactionId,
                'tag' => $data['tag'] ?? null,
                'code_transaction' => $data['code_transaction'] ?? null,
            ]);
            return response()->json(['received' => true, 'processed' => false]);
        }

        $newStatus = match ($type) {
            'PIX_CASHOUT_SUCCESS' => 'COMPLETED',
            'PIX_CASHOUT_ERROR' => 'FAILED',
            'PIX_CASHOUT_CANCELED' => 'CANCELLED',
            'PIX_CASHOUT_REFUND' => 'REFUNDED',
            default => null,
        };

        if ($newStatus === null) {
            return response()->json(['received' => true, 'processed' => false]);
        }

        $terminalStatuses = ['COMPLETED', 'FAILED', 'CANCELLED', 'REFUNDED'];
        if (in_array($payout->status, $terminalStatuses, true)) {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'already_terminal']);
        }

        $e2e = $this->resolveCashOutEndToEnd($data);
        $paymentDate = $data['payment_date'] ?? null;
        $paidAt = is_string($paymentDate) && $paymentDate !== '' ? $paymentDate : null;

        $updated = app(SimpayCashOutOutcomeService::class)->applyFinalStatusIfNeeded(
            $payout,
            $newStatus,
            $data,
            $e2e,
            $paidAt
        );

        if (! $updated) {
            return response()->json(['received' => true, 'processed' => false]);
        }

        Log::info('[SIMPAY][WEBHOOK] Status do saque atualizado', [
            'payout_id' => $payout->id,
            'transaction_id' => $transactionId,
            'new_status' => $newStatus,
            'type' => $type,
        ]);

        return response()->json(['received' => true, 'processed' => true]);
    }

    /**
     * MED (Mecanismo Especial de Devolução): registra/atualiza a infração local
     * (pix_infracoes) e aplica o efeito no depósito (bloqueio/estorno/liberação).
     * Espelha {@see \App\Http\Controllers\Api\TreealContasWebhookController}.
     *
     * @param  array<string, mixed>  $data
     */
    private function handleMed(string $type, array $data): JsonResponse
    {
        $medId = trim((string) ($data['pix_med_id'] ?? $data['pixmed_id'] ?? $data['id'] ?? ''));
        if ($medId === '') {
            Log::warning('[SIMPAY][WEBHOOK][MED] sem pix_med_id', ['type' => $type]);

            return response()->json(['received' => true, 'processed' => false, 'reason' => 'missing_med_id']);
        }

        $e2e = trim((string) ($data['endtoend'] ?? $data['end_to_end'] ?? $data['endToEndId'] ?? ''));
        $deposit = $this->findSimpayDepositForMed($data, $e2e);
        if (! $deposit) {
            Log::warning('[SIMPAY][WEBHOOK][MED] depósito não encontrado', [
                'type' => $type,
                'med_id' => $medId,
                'e2e' => $e2e,
            ]);

            return response()->json(['received' => true, 'processed' => false, 'reason' => 'deposit_not_found']);
        }

        $amount = is_numeric($data['amount'] ?? null) ? round((float) $data['amount'], 2) : (float) $deposit->amount;
        $analysisResult = strtoupper((string) ($data['analysis_result'] ?? ''));
        $creation = Carbon::now();

        $applier = app(\App\Services\Infraction\InfractionEffectApplier::class);
        $applier->upsert('simpay', $medId, [
            'user_id' => (string) $deposit->user_id,
            'transaction_id' => (string) ($deposit->idTransaction ?? ''),
            'status' => $this->mapMedStatusToLocal($type),
            'tipo' => strtolower((string) ($data['origin_situation_type'] ?? 'fraude')),
            'descricao' => mb_substr((string) ($data['description'] ?? 'Infração Pix (MED) SIMPAY registrada.'), 0, 1000),
            'valor' => $amount,
            'end_to_end' => $e2e !== '' ? $e2e : (string) ($deposit->end_to_end ?? ''),
            'data_criacao' => $creation,
            'data_limite' => $creation->copy()->addDays(5),
            'detalhes' => json_encode([
                'medId' => $medId,
                'event' => $type,
                'origin_situation_type' => $data['origin_situation_type'] ?? null,
                'analysis_result' => $analysisResult !== '' ? $analysisResult : null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'analysis_result' => $analysisResult !== '' ? $analysisResult : null,
        ]);

        // MED_CREATED bloqueia; MED_APPROVED devolve; MED_REJECTED/CANCELLED liberam.
        match ($type) {
            'MED_CREATED' => $applier->hold($deposit),
            'MED_APPROVED' => $applier->refund($deposit),
            default => $applier->release($deposit),
        };

        Log::info('[SIMPAY][WEBHOOK][MED] registrado', [
            'type' => $type,
            'med_id' => $medId,
            'deposit_id' => $deposit->id,
        ]);

        return response()->json(['received' => true, 'processed' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findSimpayDepositForMed(array $data, string $e2e): ?Solicitacoes
    {
        $base = Solicitacoes::query()->where('executor_ordem', 'simpay');

        if ($e2e !== '') {
            $found = (clone $base)->where('end_to_end', $e2e)->first();
            if ($found !== null) {
                return $found;
            }
        }

        $txId = trim((string) ($data['transaction_id'] ?? $data['qr_code_id'] ?? ''));
        if ($txId !== '') {
            $found = (clone $base)->where(function ($q) use ($txId) {
                $q->where('idTransaction', $txId)->orWhere('externalreference', $txId);
            })->first();
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function mapMedStatusToLocal(string $type): string
    {
        return match ($type) {
            'MED_CREATED' => 'PENDENTE',
            'MED_APPROVED', 'MED_REJECTED' => 'RESOLVIDA',
            'MED_CANCELLED' => 'CANCELADA',
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
