<?php

namespace App\Services\FluxPayments;

use App\Helpers\WebhookClientMessages;
use App\Jobs\ClientWebhookDispatchJob;
use App\Models\Solicitacoes;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\ClientWebhookPayloadBuilder;
use App\Services\PaymentProcessingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Fluxo MED (infração) da família A55 (FluxPayments / Paya55) — espelha
 * TreealContasWebhookController. O provider vem do webhook (ou do depósito).
 *
 * Abertura: transaction.infraction → depósito MEDIATION + pix_infracoes.
 * Encerramento favorável ao pagador: transaction.refunded/chargeback com depósito em MEDIATION
 *   → REFUNDED + débito de saldo (settle).
 * Liberação (lojista ganha): metadata.infraction status CANCELLED / CLOSED+DISAGREED
 *   → COMPLETED sem débito.
 */
class FluxPaymentsInfractionService
{
    /** @var list<string> */
    private const ACTIVE_STATUSES = [
        'OPEN',
        'ACKNOWLEDGED',
        'WAITING_ADJUSTMENTS',
        'WAITING_PSP',
        'DEFENDED',
        'ANSWERED',
    ];

    private function providerLabel(string $provider): string
    {
        return match (strtolower(trim($provider))) {
            'paya55' => 'Paya55',
            default => 'FluxPayments',
        };
    }

    /** Prefixo de log derivado do provider do próprio depósito. */
    private function tagFor(Solicitacoes $deposit): string
    {
        $provider = strtolower(trim((string) ($deposit->executor_ordem ?? ''))) ?: 'fluxpayments';

        return '['.strtoupper($provider).'][INFRACTION]';
    }

    /**
     * @param  array<string, mixed>  $data  Payload data do webhook (objeto da transação)
     * @return array{processed: bool, reason?: string, deposit_id?: int}
     */
    public function handleFromWebhook(array $data, string $objectId = '', string $provider = 'fluxpayments'): array
    {
        $provider = strtolower(trim($provider)) ?: 'fluxpayments';
        $tag = '['.strtoupper($provider).'][INFRACTION]';
        $txnId = trim((string) ($data['id'] ?? $objectId));
        $externalRef = trim((string) ($data['externalRef'] ?? ''));

        $infractionMeta = is_array($data['metadata']['infraction'] ?? null)
            ? $data['metadata']['infraction']
            : [];

        $infractionId = $this->resolveInfractionId($txnId, $infractionMeta);
        if ($infractionId === '') {
            return ['processed' => false, 'reason' => 'missing_infraction_id'];
        }

        $status = $this->resolveInfractionStatus($infractionMeta, $data);
        $analysisResult = strtoupper(trim((string) (
            $infractionMeta['analysisResult']
            ?? $infractionMeta['analysis_result']
            ?? $data['analysisResult']
            ?? ''
        )));

        $deposit = $this->findDeposit($txnId, $externalRef, $provider);
        if (! $deposit) {
            Log::warning($tag.' Depósito não localizado', [
                'infraction_id' => $infractionId,
                'txn_id' => $txnId !== '' ? $txnId : null,
                'externalRef' => $externalRef !== '' ? $externalRef : null,
            ]);

            return ['processed' => false, 'reason' => 'deposit_not_found'];
        }

        $endToEndId = $this->extractEndToEndId($data, $deposit);
        $amount = $this->extractAmount($data, $infractionMeta, (float) $deposit->amount);

        $this->upsertInfractionRecord(
            $deposit,
            $infractionId,
            $status,
            $analysisResult,
            $amount,
            $data,
            $infractionMeta,
            $endToEndId,
            $provider
        );

        $depositStatusChanged = $this->applyInfractionToDeposit($deposit, $status, $analysisResult);

        app(PaymentProcessingService::class)->invalidateInfractionCaches((string) $deposit->user_id);
        app(PaymentProcessingService::class)->invalidateCachesAfterPayment((string) $deposit->user_id);

        $this->dispatchInfractionClientWebhook($deposit->fresh(), $status, $amount);

        Log::info($tag.' Processada', [
            'infraction_id' => $infractionId,
            'status' => $status,
            'analysis_result' => $analysisResult !== '' ? $analysisResult : null,
            'deposit_id' => $deposit->id,
            'deposit_status_changed' => $depositStatusChanged,
        ]);

        return [
            'processed' => true,
            'deposit_id' => (int) $deposit->id,
        ];
    }

    /**
     * Quando chega refund/chargeback e o depósito está em MEDIATION, encerra MED como AGREED.
     *
     * @param  array<string, mixed>  $data
     */
    public function settleIfInMediation(Solicitacoes $deposit, array $data = []): bool
    {
        if (strtoupper((string) $deposit->status) !== 'MEDIATION') {
            return false;
        }

        $settled = $this->settleInfractionRefund($deposit);
        if (! $settled) {
            return false;
        }

        $this->markInfractionResolved($deposit, 'AGREED', $data);
        app(PaymentProcessingService::class)->invalidateInfractionCaches((string) $deposit->user_id);
        $this->dispatchInfractionClientWebhook($deposit->fresh(), 'CLOSED', (float) $deposit->amount);

        Log::info($this->tagFor($deposit).' MED encerrada com estorno (AGREED)', [
            'deposit_id' => $deposit->id,
            'transaction_id' => $deposit->idTransaction,
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $infractionMeta
     */
    private function resolveInfractionId(string $txnId, array $infractionMeta): string
    {
        foreach (['id', 'infractionId', 'infraction_id'] as $key) {
            $v = trim((string) ($infractionMeta[$key] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return $txnId !== '' ? $txnId.':med' : '';
    }

    /**
     * @param  array<string, mixed>  $infractionMeta
     * @param  array<string, mixed>  $data
     */
    private function resolveInfractionStatus(array $infractionMeta, array $data): string
    {
        $raw = strtoupper(trim((string) (
            $infractionMeta['status']
            ?? $data['infractionStatus']
            ?? ''
        )));

        if ($raw === '') {
            return 'OPEN';
        }

        return match ($raw) {
            'OPEN', 'PENDING', 'REPORTED', 'BLOCKED' => 'OPEN',
            'ACKNOWLEDGED', 'WAITING_ADJUSTMENTS', 'WAITING_PSP', 'DEFENDED', 'ANSWERED', 'IN_ANALYSIS', 'EM_ANALISE' => 'ACKNOWLEDGED',
            'CLOSED', 'RESOLVED', 'RESOLVIDA' => 'CLOSED',
            'CANCELLED', 'CANCELED', 'CANCELADA' => 'CANCELLED',
            default => 'OPEN',
        };
    }

    private function findDeposit(string $transactionId, string $externalRef, string $provider = 'fluxpayments'): ?Solicitacoes
    {
        $base = Solicitacoes::query()->where('executor_ordem', $provider);

        if ($transactionId !== '') {
            $found = (clone $base)->where('idTransaction', $transactionId)->first();
            if ($found !== null) {
                return $found;
            }

            $found = (clone $base)->where('end_to_end', $transactionId)->first();
            if ($found !== null) {
                return $found;
            }
        }

        if ($externalRef !== '') {
            return (clone $base)->where(function ($q) use ($externalRef) {
                $q->where('idTransaction', $externalRef)
                    ->orWhere('externalreference', $externalRef);
            })->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractEndToEndId(array $data, Solicitacoes $deposit): string
    {
        $pix = is_array($data['pix'] ?? null) ? $data['pix'] : [];
        $candidates = [
            $data['pixEnd2EndId'] ?? null,
            $data['endToEndId'] ?? null,
            $data['end2EndId'] ?? null,
            $pix['end2EndId'] ?? null,
            $pix['endToEndId'] ?? null,
            $deposit->end_to_end ?? null,
        ];

        foreach ($candidates as $v) {
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $infractionMeta
     */
    private function extractAmount(array $data, array $infractionMeta, float $fallbackReais): float
    {
        foreach (['blockedAmount', 'blocked_amount', 'amount'] as $key) {
            if (isset($infractionMeta[$key]) && is_numeric($infractionMeta[$key]) && (float) $infractionMeta[$key] > 0) {
                return $this->normalizeAmountToReais((float) $infractionMeta[$key]);
            }
        }

        if (isset($data['amount']) && is_numeric($data['amount']) && (float) $data['amount'] > 0) {
            return $this->normalizeAmountToReais((float) $data['amount']);
        }

        return round($fallbackReais, 2);
    }

    /**
     * A API envia valores monetários em centavos nos webhooks de transação.
     * Se o número for grande (>= 100 e inteiro típico de centavos), converte.
     * Fallback: se já parece reais (ex.: 100.00 com depósito 100), usa direto quando próximo do fallback.
     */
    private function normalizeAmountToReais(float $value): float
    {
        // A documentação define valores monetários em centavos nos webhooks.
        return round($value / 100, 2);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $infractionMeta
     */
    private function upsertInfractionRecord(
        Solicitacoes $deposit,
        string $infractionId,
        string $status,
        string $analysisResult,
        float $amount,
        array $data,
        array $infractionMeta,
        string $endToEndId,
        string $provider = 'fluxpayments',
    ): void {
        $hasProviderColumn = Schema::hasColumn('pix_infracoes', 'provider_infraction_id');

        $creation = $this->parseDate($infractionMeta['reportedAt'] ?? $infractionMeta['reported_at'] ?? $data['updatedAt'] ?? null)
            ?? Carbon::now();
        $limite = $this->parseDate($infractionMeta['deadline'] ?? $infractionMeta['deadlineDate'] ?? null)
            ?? $creation->copy()->addDays(5);

        $tipo = strtolower(trim((string) ($infractionMeta['type'] ?? 'med')));
        if ($tipo === '') {
            $tipo = 'med';
        }

        $descricao = trim((string) ($infractionMeta['reason'] ?? $infractionMeta['reportDetails'] ?? ''));
        if ($descricao === '') {
            $descricao = 'Infração Pix (MED) registrada via '.$this->providerLabel($provider).'.';
        }

        $attributes = [
            'user_id' => (string) $deposit->user_id,
            'transaction_id' => (string) ($deposit->idTransaction ?? ''),
            'status' => $this->mapInfractionStatusToLocal($status),
            'tipo' => $tipo,
            'descricao' => mb_substr($descricao, 0, 1000),
            'valor' => $amount,
            'end_to_end' => $endToEndId !== '' ? $endToEndId : (string) ($deposit->end_to_end ?? ''),
            'data_criacao' => $creation,
            'data_limite' => $limite,
            'detalhes' => json_encode([
                'infractionId' => $infractionId,
                'status' => $status,
                'analysisResult' => $analysisResult !== '' ? $analysisResult : null,
                'provider' => $provider,
                'reason' => $infractionMeta['reason'] ?? null,
                'blockedAmount' => $infractionMeta['blockedAmount'] ?? null,
                'transactionId' => $data['id'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => Carbon::now(),
        ];

        if ($hasProviderColumn) {
            $attributes['provider'] = $provider;
            if (Schema::hasColumn('pix_infracoes', 'analysis_result')) {
                $attributes['analysis_result'] = $analysisResult !== '' ? $analysisResult : null;
            }

            $existing = DB::table('pix_infracoes')->where('provider_infraction_id', $infractionId)->first();
            if ($existing) {
                DB::table('pix_infracoes')->where('id', $existing->id)->update($attributes);
            } else {
                $attributes['provider_infraction_id'] = $infractionId;
                $attributes['created_at'] = Carbon::now();
                DB::table('pix_infracoes')->insert($attributes);
            }

            return;
        }

        $existing = DB::table('pix_infracoes')
            ->where('user_id', $attributes['user_id'])
            ->where('transaction_id', $attributes['transaction_id'])
            ->where('tipo', $tipo)
            ->first();

        if ($existing) {
            DB::table('pix_infracoes')->where('id', $existing->id)->update($attributes);
        } else {
            $attributes['created_at'] = Carbon::now();
            DB::table('pix_infracoes')->insert($attributes);
        }
    }

    public function applyInfractionToDeposit(Solicitacoes $deposit, string $status, string $analysisResult): bool
    {
        if (in_array($status, self::ACTIVE_STATUSES, true)) {
            return DB::transaction(function () use ($deposit) {
                $locked = Solicitacoes::where('id', $deposit->id)->lockForUpdate()->first();
                if (! $locked || ! in_array($locked->status, ['PAID_OUT', 'COMPLETED'], true)) {
                    return false;
                }

                $locked->update(['status' => 'MEDIATION']);

                return true;
            });
        }

        if ($status === 'CLOSED') {
            if ($analysisResult === 'AGREED') {
                return $this->settleInfractionRefund($deposit);
            }

            return $this->releaseInfractionHold($deposit);
        }

        if ($status === 'CANCELLED') {
            return $this->releaseInfractionHold($deposit);
        }

        return false;
    }

    public function settleInfractionRefund(Solicitacoes $deposit): bool
    {
        return DB::transaction(function () use ($deposit) {
            $locked = Solicitacoes::where('id', $deposit->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'REFUNDED') {
                return false;
            }

            $previousStatus = (string) $locked->status;
            $locked->update(['status' => 'REFUNDED']);

            if (in_array($previousStatus, ['PAID_OUT', 'COMPLETED', 'MEDIATION'], true)) {
                $user = User::where('user_id', $locked->user_id)
                    ->orWhere('username', $locked->user_id)
                    ->lockForUpdate()
                    ->first();

                if ($user) {
                    app(BalanceService::class)->decrementBalanceForRefund(
                        $user,
                        (float) $locked->deposito_liquido,
                        'saldo',
                    );

                    try {
                        app(\App\Services\AffiliateCommissionService::class)
                            ->reverseCashInCommissionForRefundedDeposit($locked);
                    } catch (\Throwable $e) {
                        Log::warning($this->tagFor($locked).' Falha ao estornar comissão de afiliado', [
                            'deposit_id' => $locked->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            return true;
        });
    }

    public function releaseInfractionHold(Solicitacoes $deposit): bool
    {
        return DB::transaction(function () use ($deposit) {
            $locked = Solicitacoes::where('id', $deposit->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'MEDIATION') {
                return false;
            }

            $locked->update(['status' => 'COMPLETED']);

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function markInfractionResolved(Solicitacoes $deposit, string $analysisResult, array $data): void
    {
        if (! Schema::hasColumn('pix_infracoes', 'provider_infraction_id')) {
            return;
        }

        $txnId = (string) ($deposit->idTransaction ?? '');
        $query = DB::table('pix_infracoes')
            ->where('provider', strtolower(trim((string) ($deposit->executor_ordem ?? ''))) ?: 'fluxpayments')
            ->where(function ($q) use ($txnId, $deposit) {
                $q->where('transaction_id', $txnId);
                if ($txnId !== '') {
                    $q->orWhere('provider_infraction_id', $txnId)
                        ->orWhere('provider_infraction_id', $txnId.':med');
                }
                if (! empty($deposit->end_to_end)) {
                    $q->orWhere('end_to_end', $deposit->end_to_end);
                }
            })
            ->whereIn('status', ['PENDENTE', 'EM_ANALISE', 'MEDIATION']);

        $update = [
            'status' => 'RESOLVIDA',
            'updated_at' => Carbon::now(),
        ];
        if (Schema::hasColumn('pix_infracoes', 'analysis_result')) {
            $update['analysis_result'] = $analysisResult;
        }

        $query->update($update);
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
}
