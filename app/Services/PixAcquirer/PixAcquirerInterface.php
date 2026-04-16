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

    /**
     * Consulta o status atual de um payout na adquirente.
     *
     * @return array{success:bool,status?:string,message?:string,raw?:array}
     */
    public function getPayoutStatus(string $transactionId): array;

    /**
     * Consulta o status atual de uma cobrança (cash in) na adquirente.
     *
     * @return array{success:bool,status?:string,message?:string,raw?:array}
     */
    public function getChargeStatus(string $transactionId): array;

    /**
     * Solicita reembolso (total ou parcial) de uma cobrança PIX.
     *
     * @return array{success:bool,refundId?:string,status?:string,message?:string,raw?:array}
     */
    public function createRefund(string $transactionId, float $amount, string $reason): array;
}
