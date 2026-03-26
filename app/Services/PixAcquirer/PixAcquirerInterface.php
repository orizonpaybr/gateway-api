<?php

namespace App\Services\PixAcquirer;

interface PixAcquirerInterface
{
    public function getReference(): string;

    public function isActive(): bool;

    /**
     * @return array{success:bool,message?:string,correlationID?:string,brCode?:string,qrCodeImage?:string,expiresDate?:string,status?:string,raw?:array}
     */
    public function createCharge(
        float $amountReais,
        array $customer,
        ?string $correlationId = null,
        ?string $comment = null,
        ?string $expiresDate = null
    ): array;

    /**
     * @return array{success:bool,message?:string,referenceCode?:string,status?:string,raw?:array}
     */
    public function createPayout(
        float $amountReais,
        string $pixKey,
        string $pixKeyType,
        ?string $description = null,
        ?string $correlationId = null,
        ?string $recipientName = null,
        ?string $recipientDocument = null
    ): array;

    public function mapPayoutStatus(string $providerStatus): string;
}
