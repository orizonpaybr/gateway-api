<?php

namespace App\Http\Controllers\Api;

use App\Helpers\WebhookClientMessages;
use App\Http\Controllers\Controller;
use App\Jobs\ClientWebhookDispatchJob;
use App\Models\Solicitacoes;
use App\Services\ClientWebhookPayloadBuilder;
use App\Services\PaymentProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FyhubWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $pix = is_array($payload['pix'] ?? null) ? $payload['pix'] : $payload;
        $txid = trim((string) ($pix['txid'] ?? $payload['txid'] ?? ''));

        Log::info('[FYHUB][WEBHOOK] Evento recebido', [
            'txid' => $txid,
            'end_to_end' => $pix['endToEndId'] ?? $payload['endToEndId'] ?? null,
            'has_pix_node' => isset($payload['pix']),
        ]);

        if ($txid === '') {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'missing_txid']);
        }

        $deposit = Solicitacoes::where('idTransaction', $txid)
            ->where('executor_ordem', 'fyhub')
            ->first();

        if (! $deposit) {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'deposit_not_found']);
        }

        $devolution = $this->extractDevolution($pix);
        if (($devolution['status'] ?? null) === 'DEVOLVIDO') {
            return $this->handleRefunded($deposit, $devolution, $pix);
        }

        if (in_array($deposit->status, ['PAID_OUT', 'COMPLETED'], true)) {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'already_paid']);
        }

        $endToEndId = trim((string) ($pix['endToEndId'] ?? $payload['endToEndId'] ?? ''));
        $paidAt = $pix['horario'] ?? $payload['horario'] ?? null;

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
            Log::error('[FYHUB][WEBHOOK] Falha ao creditar depósito', [
                'deposit_id' => $deposit->id,
                'txid' => $txid,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->dispatchClientWebhook($deposit->fresh(), 'PAID_OUT', is_string($paidAt) ? $paidAt : null);

        return response()->json(['received' => true, 'processed' => true]);
    }

    /**
     * @param array<string,mixed> $devolution
     * @param array<string,mixed> $pix
     */
    private function handleRefunded(Solicitacoes $deposit, array $devolution, array $pix): JsonResponse
    {
        if ($deposit->status === 'REFUNDED') {
            return response()->json(['received' => true, 'processed' => false, 'reason' => 'already_refunded']);
        }

        $updated = DB::transaction(function () use ($deposit, $pix) {
            $locked = Solicitacoes::where('id', $deposit->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'REFUNDED') {
                return false;
            }

            $updateData = ['status' => 'REFUNDED'];
            $endToEndId = trim((string) ($pix['endToEndId'] ?? ''));
            if ($endToEndId !== '') {
                $updateData['end_to_end'] = $endToEndId;
            }

            $locked->update($updateData);
            return true;
        });

        if (! $updated) {
            return response()->json(['received' => true, 'processed' => false]);
        }

        Log::info('[FYHUB][WEBHOOK] Depósito marcado como estornado', [
            'deposit_id' => $deposit->id,
            'txid' => $deposit->idTransaction,
            'devolution_id' => $devolution['id'] ?? null,
            'rtr_id' => $devolution['rtrId'] ?? null,
        ]);

        app(PaymentProcessingService::class)->invalidateCachesAfterPayment($deposit->user_id);
        $this->dispatchClientWebhook($deposit->fresh(), 'REFUNDED');

        return response()->json(['received' => true, 'processed' => true]);
    }

    /**
     * @param array<string,mixed> $pix
     * @return array<string,mixed>|null
     */
    private function extractDevolution(array $pix): ?array
    {
        $devolucoes = $pix['devolucoes'] ?? null;

        if (is_array($devolucoes) && isset($devolucoes['status'])) {
            return $devolucoes;
        }

        if (is_array($devolucoes) && isset($devolucoes[0]) && is_array($devolucoes[0])) {
            return $devolucoes[0];
        }

        return null;
    }

    private function dispatchClientWebhook(
        Solicitacoes $deposit,
        string $status = 'PAID_OUT',
        ?string $paymentDate = null
    ): void
    {
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
