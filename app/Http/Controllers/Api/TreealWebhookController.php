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

/**
 * Webhook CashIn TREEAL (listaPix).
 *
 * A Treeal envia POST {webhookUrl}/pix com Pix recebidos associados a txid.
 */
class TreealWebhookController extends Controller
{
    public function handlePix(Request $request): JsonResponse
    {
        if (! $this->passesOptionalAuthHeader($request)) {
            Log::warning('[TREEAL][WEBHOOK] Header de autenticação inválido ou ausente', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['received' => false, 'error' => 'unauthorized'], 401);
        }

        $payload = $request->all();
        $pixItems = $this->extractPixItems($payload);

        if ($pixItems === []) {
            Log::info('[TREEAL][WEBHOOK] Payload sem Pix processável', [
                'keys' => array_keys($payload),
            ]);

            return response()->json(['received' => true, 'processed' => false, 'reason' => 'missing_pix']);
        }

        $results = [];
        foreach ($pixItems as $pix) {
            $results[] = $this->processPixItem($pix);
        }

        $anyProcessed = in_array(true, array_column($results, 'processed'), true);

        return response()->json([
            'received' => true,
            'processed' => $anyProcessed,
            'results' => $results,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractPixItems(array $payload): array
    {
        if (isset($payload['pix']) && is_array($payload['pix'])) {
            if (array_is_list($payload['pix'])) {
                return array_values(array_filter($payload['pix'], 'is_array'));
            }

            if (isset($payload['pix']['txid']) || isset($payload['pix']['endToEndId'])) {
                return [$payload['pix']];
            }
        }

        if (isset($payload['txid']) || isset($payload['endToEndId'])) {
            return [$payload];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $pix
     * @return array{processed: bool, reason?: string, txid?: string}
     */
    private function processPixItem(array $pix): array
    {
        $txid = trim((string) ($pix['txid'] ?? ''));
        if ($txid === '') {
            return ['processed' => false, 'reason' => 'missing_txid'];
        }

        Log::info('[TREEAL][WEBHOOK] Pix recebido', [
            'txid' => $txid,
            'end_to_end' => $pix['endToEndId'] ?? null,
        ]);

        $deposit = Solicitacoes::where('idTransaction', $txid)
            ->where('executor_ordem', 'treeal')
            ->first();

        if (! $deposit) {
            return ['processed' => false, 'reason' => 'deposit_not_found', 'txid' => $txid];
        }

        $devolution = $this->extractDevolution($pix);
        if (($devolution['status'] ?? null) === 'DEVOLVIDO') {
            return $this->handleRefunded($deposit, $devolution, $pix);
        }

        if (in_array($deposit->status, ['PAID_OUT', 'COMPLETED'], true)) {
            return ['processed' => false, 'reason' => 'already_paid', 'txid' => $txid];
        }

        $endToEndId = trim((string) ($pix['endToEndId'] ?? ''));
        $paidAt = $pix['horario'] ?? null;

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
            return ['processed' => false, 'reason' => 'concurrent_update', 'txid' => $txid];
        }

        try {
            app(PaymentProcessingService::class)->processPaymentReceived(Solicitacoes::findOrFail($deposit->id));
        } catch (\Throwable $e) {
            Log::error('[TREEAL][WEBHOOK] Falha ao creditar depósito', [
                'deposit_id' => $deposit->id,
                'txid' => $txid,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->dispatchClientWebhook($deposit->fresh(), 'PAID_OUT', is_string($paidAt) ? $paidAt : null);

        return ['processed' => true, 'txid' => $txid];
    }

    /**
     * @param  array<string, mixed>  $devolution
     * @param  array<string, mixed>  $pix
     * @return array{processed: bool, reason?: string, txid?: string}
     */
    private function handleRefunded(Solicitacoes $deposit, array $devolution, array $pix): array
    {
        if ($deposit->status === 'REFUNDED') {
            return ['processed' => false, 'reason' => 'already_refunded', 'txid' => $deposit->idTransaction];
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
            return ['processed' => false, 'reason' => 'concurrent_update', 'txid' => $deposit->idTransaction];
        }

        Log::info('[TREEAL][WEBHOOK] Depósito marcado como estornado', [
            'deposit_id' => $deposit->id,
            'txid' => $deposit->idTransaction,
            'devolution_id' => $devolution['id'] ?? null,
            'rtr_id' => $devolution['rtrId'] ?? null,
        ]);

        app(PaymentProcessingService::class)->invalidateCachesAfterPayment($deposit->user_id);
        $this->dispatchClientWebhook($deposit->fresh(), 'REFUNDED');

        return ['processed' => true, 'txid' => $deposit->idTransaction];
    }

    /**
     * @param  array<string, mixed>  $pix
     * @return array<string, mixed>|null
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
        $headerName = trim((string) config('treeal.webhook_auth_header', ''));
        $expectedValue = (string) config('treeal.webhook_auth_value', '');

        if ($headerName === '' || $expectedValue === '') {
            return true;
        }

        $received = $request->header($headerName);

        return is_string($received) && hash_equals($expectedValue, $received);
    }
}
