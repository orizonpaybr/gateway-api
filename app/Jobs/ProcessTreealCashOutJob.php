<?php

namespace App\Jobs;

use App\Helpers\Helper;
use App\Helpers\PixErrorCodes;
use App\Jobs\ClientWebhookDispatchJob;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\PaymentProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Processa webhook de Cash Out (saque PIX Treeal) em background.
 *
 * Permite que o webhook responda 200 imediatamente à Treeal (evita retentativas)
 * e o processamento de saldo ocorra em poucos segundos via fila.
 *
 * Status tratados:
 * - LIQUIDATED / COMPLETED / PAID / CONCLUIDO → confirma saque (debita saldo)
 * - CANCELED / CANCELLED / FAILED             → reverte saldo se já debitado
 * - REFUNDED / PARTIALLY_REFUNDED             → reverte saldo (total ou parcial)
 * - Outros                                    → apenas atualiza status
 */
class ProcessTreealCashOutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    /** @param array<string, mixed> $data Payload completo recebido da Treeal */
    public function __construct(
        public string $transactionId,
        public ?string $status,
        public array $data,
        public int $webhookLogId
    ) {}

    public function handle(PaymentProcessingService $paymentService): void
    {
        $jobStart = microtime(true);

        $endToEndId = $this->data['endToEndId'] ?? $this->data['end_to_end_id'] ?? null;

        $cashOut = SolicitacoesCashOut::where('idTransaction', $this->transactionId)
            ->orWhere('externalreference', $this->transactionId)
            ->orWhere('end_to_end', $this->transactionId)
            ->when($endToEndId && $endToEndId !== $this->transactionId, fn($q) => $q->orWhere('end_to_end', $endToEndId))
            ->first();

        if (!$cashOut) {
            Log::warning('[TREEAL CashOut Job] Saque não encontrado', [
                'transaction_id' => $this->transactionId,
                'end_to_end_id'  => $endToEndId,
            ]);
            $this->failWebhookLog('Saque não encontrado no banco de dados');
            return;
        }

        $statusUpper = strtoupper($this->status ?? '');

        $statusConfirmado = ['LIQUIDATED', 'COMPLETED', 'PAID', 'CONCLUIDO', 'PROCESSED'];
        $statusCancelado  = ['CANCELED', 'CANCELLED', 'FAILED'];
        $statusEstornado  = ['REFUNDED', 'PARTIALLY_REFUNDED'];

        $updateData = [];
        if ($endToEndId && empty($cashOut->end_to_end)) {
            $updateData['end_to_end'] = $endToEndId;
        }

        try {
            if (in_array($statusUpper, $statusConfirmado)) {
                if (in_array($cashOut->status, ['PAID_OUT', 'COMPLETED'])) {
                    Log::info('[TREEAL CashOut Job] Saque já processado (idempotência)', [
                        'transaction_id' => $this->transactionId,
                    ]);
                    if (!empty($updateData)) {
                        $cashOut->update($updateData);
                    }
                    $this->markWebhookProcessed();
                    return;
                }

                // Conformidade: validar valor do webhook vs valor armazenado
                $webhookAmount = $this->extractAmountFromPayload($this->data);
                $storedAmount = (float) $cashOut->amount;
                if ($webhookAmount !== null && !$this->amountsMatch($webhookAmount, $storedAmount)) {
                    Log::warning('[TREEAL CashOut Job] Divergência de valor (webhook vs banco)', [
                        'transaction_id'  => $this->transactionId,
                        'webhook_amount'  => $webhookAmount,
                        'stored_amount'   => $storedAmount,
                    ]);
                    if (config('treeal.strict_amount_validation', false)) {
                        $this->failWebhookLog('Divergência de valor: webhook ' . $webhookAmount . ' != banco ' . $storedAmount);
                        return;
                    }
                }

                $paymentService->processWithdrawal($cashOut);

                $cashOut->update([
                    'executor_ordem' => 'Treeal',
                    'end_to_end'     => $endToEndId ?? $cashOut->end_to_end,
                ]);

                Log::info('[TREEAL CashOut Job] Saque confirmado e processado', [
                    'transaction_id' => $this->transactionId,
                    'amount'         => $cashOut->amount,
                    'user_id'        => $cashOut->user_id,
                    'duration_ms'    => round((microtime(true) - $jobStart) * 1000, 2),
                ]);

                if (!empty($cashOut->callback) && $cashOut->callback !== 'web') {
                    ClientWebhookDispatchJob::dispatch(
                        $cashOut->callback,
                        $cashOut->idTransaction ?? (string) $cashOut->id,
                        'PAID_OUT',
                        (float) $cashOut->amount,
                        now()->toIso8601String(),
                        $this->buildCashOutWebhookExtra($cashOut)
                    )->onQueue('webhooks');
                }

                $this->markWebhookProcessed();
                return;
            }

            if (in_array($statusUpper, $statusCancelado)) {
                Log::warning('[TREEAL CashOut Job] Saque cancelado', [
                    'transaction_id' => $this->transactionId,
                    'reason'         => PixErrorCodes::getMessageFromPayload($this->data, 'Não informado'),
                    'current_status' => $cashOut->status,
                ]);

                if (in_array($cashOut->status, ['PROCESSING', 'PAID_OUT', 'COMPLETED'])) {
                    $this->reverterSaldo($cashOut, 'cancelamento');
                }

                $cashOut->update([
                    'status'     => 'CANCELLED',
                    'end_to_end' => $endToEndId ?? $cashOut->end_to_end,
                ]);

                if (!empty($cashOut->callback) && $cashOut->callback !== 'web') {
                    ClientWebhookDispatchJob::dispatch(
                        $cashOut->callback,
                        $cashOut->idTransaction ?? (string) $cashOut->id,
                        'CANCELLED',
                        (float) $cashOut->amount,
                        now()->toIso8601String(),
                        $this->buildCashOutWebhookExtra($cashOut)
                    )->onQueue('webhooks');
                }

                $this->markWebhookProcessed();
                return;
            }

            if (in_array($statusUpper, $statusEstornado)) {
                Log::warning('[TREEAL CashOut Job] Saque estornado', [
                    'transaction_id' => $this->transactionId,
                    'status'         => $this->status,
                ]);

                if (in_array($cashOut->status, ['PAID_OUT', 'COMPLETED'])) {
                    $isPartial   = $statusUpper === 'PARTIALLY_REFUNDED';
                    $refundAmount = $isPartial
                        ? ($this->data['refundAmount'] ?? $this->data['amount'] ?? $cashOut->amount)
                        : $cashOut->amount;

                    $this->reverterSaldo($cashOut, 'estorno', (float) $refundAmount);
                }

                $internalStatus = $statusUpper === 'PARTIALLY_REFUNDED' ? 'PARTIALLY_REFUNDED' : 'REFUNDED';
                $cashOut->update([
                    'status'     => $internalStatus,
                    'end_to_end' => $endToEndId ?? $cashOut->end_to_end,
                ]);

                if (!empty($cashOut->callback) && $cashOut->callback !== 'web') {
                    ClientWebhookDispatchJob::dispatch(
                        $cashOut->callback,
                        $cashOut->idTransaction ?? (string) $cashOut->id,
                        $internalStatus,
                        (float) $cashOut->amount,
                        now()->toIso8601String(),
                        $this->buildCashOutWebhookExtra($cashOut)
                    )->onQueue('webhooks');
                }

                $this->markWebhookProcessed();
                return;
            }

            $internalStatus = $this->mapStatus($this->status);
            if (!empty($updateData) || $cashOut->status !== $internalStatus) {
                $updateData['status'] = $internalStatus;
                $cashOut->update($updateData);
            }

            Log::info('[TREEAL CashOut Job] Status intermediário atualizado', [
                'transaction_id' => $this->transactionId,
                'status'         => $internalStatus,
                'duration_ms'    => round((microtime(true) - $jobStart) * 1000, 2),
            ]);

            $this->markWebhookProcessed();

        } catch (\Throwable $e) {
            Log::error('[TREEAL CashOut Job] Erro ao processar saque', [
                'transaction_id' => $this->transactionId,
                'error'          => $e->getMessage(),
                'duration_ms'    => round((microtime(true) - $jobStart) * 1000, 2),
            ]);
            $this->failWebhookLog($e->getMessage());
            throw $e;
        }
    }

    private function reverterSaldo(SolicitacoesCashOut $cashOut, string $motivo, ?float $valorEstornado = null): void
    {
        $user = User::where('user_id', $cashOut->user_id)->first();

        if (!$user) {
            Log::warning("[TREEAL CashOut Job] Usuário não encontrado para reverter saldo de {$motivo}", [
                'user_id' => $cashOut->user_id,
            ]);
            return;
        }

        $valorPrincipal     = $valorEstornado ?? $cashOut->amount;
        $valorTaxas         = $cashOut->taxa_cash_out ?? 0;
        $valorTotalReverter = $valorPrincipal + $valorTaxas;

        $balanceService = app(\App\Services\BalanceService::class);
        $balanceService->incrementBalance($user, $valorTotalReverter, 'saldo');

        Helper::calculaSaldoLiquido($user->user_id);

        Log::info("[TREEAL CashOut Job] Saldo revertido por {$motivo}", [
            'transaction_id'       => $this->transactionId,
            'user_id'              => $user->user_id,
            'valor_principal'      => $valorPrincipal,
            'valor_taxas'          => $valorTaxas,
            'valor_total_revertido' => $valorTotalReverter,
        ]);
    }

    private function mapStatus(?string $status): string
    {
        if ($status === null || $status === '') {
            return 'WAITING_FOR_APPROVAL';
        }

        $map = [
            'PROCESSING'         => 'PROCESSING',
            'PROCESSED'          => 'PAID_OUT',
            'LIQUIDATED'         => 'PAID_OUT',
            'COMPLETED'          => 'PAID_OUT',
            'PAID'               => 'PAID_OUT',
            'CANCELED'           => 'CANCELLED',
            'CANCELLED'          => 'CANCELLED',
            'FAILED'             => 'FAILED',
            'REFUNDED'           => 'REFUNDED',
            'PARTIALLY_REFUNDED' => 'PARTIALLY_REFUNDED',
        ];

        return $map[strtoupper($status)] ?? 'PENDING';
    }

    private function markWebhookProcessed(): void
    {
        WebhookLog::where('id', $this->webhookLogId)->update(['status' => 'PROCESSED']);
    }

    private function failWebhookLog(string $error): void
    {
        WebhookLog::where('id', $this->webhookLogId)->update([
            'status' => 'FAILED',
            'error'  => $error,
        ]);
    }

    /**
     * Monta payload extra do webhook para o cliente (Cash Out): tipo, beneficiário e conta que solicitou.
     *
     * @return array<string, mixed>
     */
    private function buildCashOutWebhookExtra(SolicitacoesCashOut $cashOut): array
    {
        $pixKey = $cashOut->pix ?? $cashOut->pixkey ?? null;

        return [
            'typeTransaction' => 'PIX_OUT',
            'beneficiary' => [
                'name'     => $cashOut->beneficiaryname ?? null,
                'document' => $cashOut->beneficiarydocument ?? null,
                'pixKey'   => $pixKey,
            ],
            'sender' => [
                'user_id' => $cashOut->user_id ?? null,
            ],
        ];
    }

    /** Extrai valor monetário do payload do webhook Treeal (Cash Out). */
    private function extractAmountFromPayload(array $data): ?float
    {
        $valor = $data['amount'] ?? $data['valor'] ?? null;
        if ($valor === null && isset($data['data']) && is_array($data['data'])) {
            $valor = $data['data']['amount'] ?? $data['data']['valor'] ?? null;
        }
        if ($valor === null && isset($data['componentesValor']['original']['valor'])) {
            $valor = $data['componentesValor']['original']['valor'];
        }
        if ($valor === null) {
            return null;
        }
        return (float) $valor;
    }

    /** Compara dois valores com tolerância para arredondamento (2 casas). */
    private function amountsMatch(float $a, float $b): bool
    {
        return abs(round($a, 2) - round($b, 2)) < 0.005;
    }
}
