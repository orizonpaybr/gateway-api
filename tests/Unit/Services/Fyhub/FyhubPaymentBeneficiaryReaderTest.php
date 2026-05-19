<?php

namespace Tests\Unit\Services\Fyhub;

use App\Services\Fyhub\FyhubPaymentBeneficiaryReader;
use PHPUnit\Framework\TestCase;

class FyhubPaymentBeneficiaryReaderTest extends TestCase
{
    public function test_creditor_from_get_pix_payment_doc_shape(): void
    {
        $raw = [
            'data' => [
                'id' => 946830,
                'endToEndId' => 'E43978697202605191647379796adbdd',
                'pixKey' => '+5511950059561',
                'status' => 'LIQUIDATED',
                'creditDebitType' => 'DEBIT',
                'creditorAccount' => [
                    'name' => 'Recebedor Pix Dict',
                    'document' => '11950059561',
                ],
                'debtorAccount' => [
                    'name' => 'Conta Pagadora',
                    'document' => '11222333000181',
                ],
            ],
        ];

        $resolved = FyhubPaymentBeneficiaryReader::creditorFromPayload($raw);

        $this->assertSame('Recebedor Pix Dict', $resolved['name']);
        $this->assertSame('119.500.595-61', $resolved['document']);
        $this->assertSame(946830, FyhubPaymentBeneficiaryReader::paymentId($raw));
    }

    public function test_creditor_from_transfer_webhook_doc_shape(): void
    {
        $raw = [
            'type' => 'TRANSFER',
            'data' => [
                'creditDebitType' => 'DEBIT',
                'creditorAccount' => [
                    'name' => 'Recebedor Webhook',
                    'document' => '52998224725',
                ],
            ],
        ];

        $resolved = FyhubPaymentBeneficiaryReader::creditorFromPayload($raw);

        $this->assertSame('Recebedor Webhook', $resolved['name']);
        $this->assertSame('529.982.247-25', $resolved['document']);
    }

    public function test_creditor_from_account_transaction_details_array(): void
    {
        $details = [
            [
                'id' => 123,
                'creditorAccount' => [
                    'name' => 'Recebedor Extrato',
                    'document' => '98765432100',
                ],
            ],
        ];

        $resolved = FyhubPaymentBeneficiaryReader::creditorFromAccountTransactionDetails($details);

        $this->assertSame('Recebedor Extrato', $resolved['name']);
        $this->assertSame('987.654.321-00', $resolved['document']);
    }
}
