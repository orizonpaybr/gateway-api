<?php

namespace Tests\Unit\Services\CashOut;

use App\Services\CashOut\CashOutBeneficiaryResolver;
use PHPUnit\Framework\TestCase;

class CashOutBeneficiaryResolverTest extends TestCase
{
    public function test_resolves_creditor_account_from_fyhub_webhook_data(): void
    {
        $raw = [
            'status' => 'LIQUIDATED',
            'endToEndId' => 'E123',
            'creditorAccount' => [
                'name' => 'Recebedor Creditor Account',
                'document' => '12345678901',
            ],
        ];

        $resolved = CashOutBeneficiaryResolver::resolve($raw);

        $this->assertSame('Recebedor Creditor Account', $resolved['name']);
        $this->assertSame('123.456.789-01', $resolved['document']);
    }

    public function test_skips_creditor_when_same_as_debtor_and_uses_receiver(): void
    {
        $raw = [
            'debtorAccount' => [
                'name' => 'Conta Liquidação Pagador',
                'document' => '11222333000181',
            ],
            'creditorAccount' => [
                'name' => 'Conta Liquidação Pagador',
                'document' => '11222333000181',
            ],
            'receiverAccount' => [
                'name' => 'Recebedor Chave Pix',
                'document' => '98765432100',
            ],
        ];

        $resolved = CashOutBeneficiaryResolver::resolve($raw);

        $this->assertSame('Recebedor Chave Pix', $resolved['name']);
        $this->assertSame('987.654.321-00', $resolved['document']);
    }

    public function test_resolves_flat_recipient_fields(): void
    {
        $raw = [
            'recipient_name' => 'Recebedor Campos Flat',
            'recipient_legal_id' => '52998224725',
        ];

        $resolved = CashOutBeneficiaryResolver::resolve($raw);

        $this->assertSame('Recebedor Campos Flat', $resolved['name']);
        $this->assertSame('529.982.247-25', $resolved['document']);
    }

    public function test_returns_empty_when_no_beneficiary_data(): void
    {
        $this->assertSame([], CashOutBeneficiaryResolver::resolve(['status' => 'FAILED']));
        $this->assertSame([], CashOutBeneficiaryResolver::resolve(null));
    }
}
