<?php

namespace App\Services\Treeal;

use App\Services\PixAcquirer\PixAcquirerInterface;
use App\Services\TreealContas\TreealContasAuthService;

class TreealPixAcquirerService implements PixAcquirerInterface
{
    private const STUB_MESSAGE = 'Treeal: operação não implementada — aguardando documentação';

    public function __construct(
        private readonly TreealAuthService $auth,
        private readonly TreealContasAuthService $contasAuth,
    ) {}

    public function getReference(): string
    {
        return 'treeal';
    }

    public function isActive(): bool
    {
        return ! empty(config('treeal.client_id'))
            && ! empty(config('treeal.client_secret'))
            && ! empty(config('treeal.base_url'))
            && ! empty(config('treeal.pix_key'))
            && TreealMtlsOptions::isConfigured();
    }

    public function createCharge(
        float $amountReais,
        array $customer,
        ?string $correlationId = null,
        ?string $comment = null,
        ?string $expiresDate = null
    ): array {
        return $this->notImplemented();
    }

    public function createPayout(
        float $amountReais,
        string $pixKey,
        string $pixKeyType,
        ?string $description = null,
        ?string $correlationId = null,
        ?string $recipientName = null,
        ?string $recipientDocument = null
    ): array {
        return $this->notImplemented();
    }

    public function mapPayoutStatus(string $providerStatus): string
    {
        $normalized = strtoupper(str_replace(' ', '_', trim($providerStatus)));

        return match ($normalized) {
            'LIQUIDATED' => 'COMPLETED',
            'CANCELED', 'CANCELLED' => 'CANCELLED',
            'REFUNDED', 'PARTIALLY_REFUNDED' => 'REFUNDED',
            'PROCESSING', 'ON_QUEUE', 'WAITING_CONFIRMATION', 'WAITING_SETTLEMENTCORE' => 'PROCESSING',
            default => 'PROCESSING',
        };
    }

    public function getPayoutStatus(string $transactionId, ?string $e2eId = null): array
    {
        return $this->notImplemented();
    }

    public function getChargeStatus(string $transactionId): array
    {
        return $this->notImplemented();
    }

    public function createRefund(string $transactionId, float $amount, string $reason): array
    {
        return $this->notImplemented();
    }

    /**
     * @return array{success: false, message: string}
     */
    private function notImplemented(): array
    {
        return [
            'success' => false,
            'message' => self::STUB_MESSAGE,
        ];
    }
}
