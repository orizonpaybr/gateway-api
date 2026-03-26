<?php

namespace App\Services\PixAcquirer;

class NullPixAcquirerService implements PixAcquirerInterface
{
    public function __construct(
        private readonly string $reference = 'unknown'
    ) {
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function isActive(): bool
    {
        return false;
    }

    public function createCharge(
        float $amountReais,
        array $customer,
        ?string $correlationId = null,
        ?string $comment = null,
        ?string $expiresDate = null
    ): array {
        return [
            'success' => false,
            'message' => 'Adquirente PIX não implementada ou inativa.',
        ];
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
        return [
            'success' => false,
            'message' => 'Adquirente PIX não implementada ou inativa.',
        ];
    }

    public function mapPayoutStatus(string $providerStatus): string
    {
        return 'PENDING';
    }
}
