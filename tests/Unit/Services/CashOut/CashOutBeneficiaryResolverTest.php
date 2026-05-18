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
                'name' => 'Maria Recebedora',
                'document' => '12345678901',
            ],
        ];

        $resolved = CashOutBeneficiaryResolver::resolve($raw);

        $this->assertSame('Maria Recebedora', $resolved['name']);
        $this->assertSame('123.456.789-01', $resolved['document']);
    }

    public function test_resolves_creditor_account_nested_under_data(): void
    {
        $raw = [
            'data' => [
                'creditorAccount' => [
                    'name' => 'João Pix',
                    'document' => '11222333000181',
                ],
            ],
        ];

        $resolved = CashOutBeneficiaryResolver::resolve($raw);

        $this->assertSame('João Pix', $resolved['name']);
        $this->assertSame('11.222.333/0001-81', $resolved['document']);
    }

    public function test_returns_empty_when_no_creditor_account(): void
    {
        $this->assertSame([], CashOutBeneficiaryResolver::resolve(['status' => 'FAILED']));
        $this->assertSame([], CashOutBeneficiaryResolver::resolve(null));
    }
}
