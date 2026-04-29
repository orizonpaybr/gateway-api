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

    /**
     * Eventos cash-out apenas informativos: saque já está PROCESSING no gateway após a API síncrona.
     * Responder processed=true evita reenvios desnecessários pelo provedor.
     */
    private const CASH_OUT_ACK_ONLY_TYPES = [
        'PIX_CASHOUT_CREATED',
    ];

    public function handle(Request $request): JsonResponse
    {
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

        Log::info('[SIMPAY][WEBHOOK] Tipo de evento ignorado', ['type' => $type]);

        return response()->json(['received' => true, 'processed' => false]);
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

        $payout = SolicitacoesCashOut::where('idTransaction', $transactionId)
            ->where('executor_ordem', 'simpay')
            ->first();

        if (! $payout) {
            Log::warning('[SIMPAY][WEBHOOK] Saque não encontrado', [
                'type' => $type,
                'transaction_id' => $transactionId,
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

        ClientWebhookDispatchJob::dispatch(
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
