<?php

namespace App\Services\Fyhub;

use App\Models\SolicitacoesCashOut;
use Illuminate\Support\Facades\Log;

/**
 * Preenche nome/documento do recebedor antes do postback ao integrador.
 *
 * Fontes (doc FyHub Contas):
 * 1. Payload já recebido (webhook TRANSFER com data.creditorAccount)
 * 2. GET /pix/payments/{endToEndId} → data.creditorAccount
 * 3. GET /accounts/transactions/{id}/details → creditorAccount (fallback)
 */
final class FyhubCashOutBeneficiaryEnricher
{
    /** Poll leve na requisição HTTP (não bloqueia workers por segundos). */
    public const SYNC_API_ATTEMPTS = 2;

    public const SYNC_API_SLEEP_MICROSECONDS = 300_000;

    /** Uma consulta por execução de job; retries espaçados na fila. */
    public const ASYNC_API_ATTEMPTS = 1;

    public const ASYNC_API_SLEEP_MICROSECONDS = 0;

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<string, mixed>
     */
    public function enrich(
        SolicitacoesCashOut $payout,
        ?array $raw = null,
        ?int $apiAttempts = null,
        ?int $apiSleepMicroseconds = null,
    ): array {
        $merged = is_array($raw) ? $raw : [];

        if ($payout->executor_ordem !== 'fyhub') {
            return $merged;
        }

        $beneficiary = FyhubPaymentBeneficiaryReader::creditorFromPayload($merged);

        if ($beneficiary === []) {
            $beneficiary = $this->fetchFromFyhubApis(
                $payout,
                $merged,
                $apiAttempts ?? self::SYNC_API_ATTEMPTS,
                $apiSleepMicroseconds ?? self::SYNC_API_SLEEP_MICROSECONDS,
            );
        }

        if ($beneficiary !== []) {
            $merged = $this->injectCreditorIntoPayload($merged, $beneficiary);
            $payout->update([
                'beneficiaryname' => $beneficiary['name'] ?? '',
                'beneficiarydocument' => $beneficiary['document'] ?? '',
            ]);

            Log::info('[FYHUB][BENEFICIARY] Recebedor atualizado na transação', [
                'payout_id' => $payout->id,
                'transaction_id' => $payout->idTransaction,
                'beneficiaryname' => $beneficiary['name'] ?? null,
                'beneficiarydocument' => $beneficiary['document'] ?? null,
            ]);
        } else {
            Log::warning('[FYHUB][BENEFICIARY] Recebedor não encontrado nas APIs FyHub', [
                'payout_id' => $payout->id,
                'transaction_id' => $payout->idTransaction,
                'end_to_end' => $payout->end_to_end,
            ]);
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $merged
     * @return array{name?: string, document?: string}
     */
    private function fetchFromFyhubApis(
        SolicitacoesCashOut $payout,
        array $merged,
        int $maxAttempts = self::SYNC_API_ATTEMPTS,
        int $sleepMicroseconds = self::SYNC_API_SLEEP_MICROSECONDS,
    ): array {
        $fyhub = app(FyhubPixAcquirerService::class);
        if (! $fyhub->isActive()) {
            return [];
        }

        $e2e = trim((string) ($payout->end_to_end ?? ''));
        $tid = trim((string) ($payout->idTransaction ?? ''));
        if ($e2e === '' && str_starts_with($tid, 'E')) {
            $e2e = $tid;
        }

        $fyhubPaymentId = FyhubPaymentBeneficiaryReader::paymentId($merged);

        // FyHub costuma preencher creditorAccount no GET alguns segundos após LIQUIDATED.
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            if ($attempt > 0) {
                usleep($sleepMicroseconds);
            }

            if ($e2e !== '') {
                $payment = $fyhub->getPayoutStatus($tid, $e2e);
                if ($payment['success'] ?? false) {
                    $paymentRaw = is_array($payment['raw'] ?? null) ? $payment['raw'] : [];
                    $merged = array_merge($merged, $paymentRaw);

                    $fromPayment = FyhubPaymentBeneficiaryReader::creditorFromPayload($paymentRaw);
                    if ($fromPayment !== []) {
                        return $fromPayment;
                    }

                    $fyhubPaymentId = $fyhubPaymentId ?? FyhubPaymentBeneficiaryReader::paymentId($paymentRaw);
                } else {
                    Log::warning('[FYHUB][BENEFICIARY] GET /pix/payments falhou', [
                        'end_to_end' => $e2e,
                        'attempt' => $attempt + 1,
                        'message' => $payment['message'] ?? null,
                    ]);
                }
            }

            if ($fyhubPaymentId !== null) {
                $details = $fyhub->getAccountTransactionDetails($fyhubPaymentId);
                if ($details['success'] ?? false) {
                    $fromDetails = FyhubPaymentBeneficiaryReader::creditorFromAccountTransactionDetails(
                        is_array($details['data'] ?? null) ? $details['data'] : null
                    );
                    if ($fromDetails !== []) {
                        return $fromDetails;
                    }
                }
            }
        }

        return [];
    }

    /**
     * Garante creditorAccount no payload para o ClientWebhookPayloadBuilder.
     *
     * @param  array<string, mixed>  $merged
     * @param  array{name?: string, document?: string}  $beneficiary
     * @return array<string, mixed>
     */
    private function injectCreditorIntoPayload(array $merged, array $beneficiary): array
    {
        $documentDigits = isset($beneficiary['document'])
            ? preg_replace('/\D/', '', (string) $beneficiary['document'])
            : '';

        $creditorAccount = array_filter([
            'name' => $beneficiary['name'] ?? null,
            'document' => $documentDigits !== '' ? $documentDigits : null,
        ], fn ($v) => $v !== null && $v !== '');

        $data = FyhubPaymentBeneficiaryReader::paymentData($merged);
        if ($data === []) {
            $data = $merged;
        }
        $data['creditorAccount'] = array_merge(
            is_array($data['creditorAccount'] ?? null) ? $data['creditorAccount'] : [],
            $creditorAccount
        );

        $merged['data'] = $data;
        $merged['creditorAccount'] = $data['creditorAccount'];

        return $merged;
    }
}
