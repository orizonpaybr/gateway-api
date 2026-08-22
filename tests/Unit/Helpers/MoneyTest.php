<?php

namespace Tests\Unit\Helpers;

use App\Helpers\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /** @test */
    public function soma_nao_dá_drift_de_float(): void
    {
        // Float cru: 0.1 + 0.2 = 0.30000000000000004
        $this->assertSame(0.3, Money::add(0.1, 0.2));
        $this->assertSame(100.30, Money::add(100.10, 0.20));
    }

    /** @test */
    public function subtracao_e_split_exatos(): void
    {
        // taxa cliente 1.00 − custo 0.75 = 0.25 (líquido do split)
        $this->assertSame(0.25, Money::sub(1.00, 0.75));
        $this->assertSame(9.93, Money::sub(10.00, 0.07));
    }

    /** @test */
    public function centavos_ida_e_volta(): void
    {
        $this->assertSame(10050, Money::toCents(100.50));
        $this->assertSame(100.50, Money::fromCents(10050));
        // 0.29 * 100 sem cuidado vira 28 (float); toCents corrige.
        $this->assertSame(29, Money::toCents(0.29));
    }

    /** @test */
    public function compara_valores(): void
    {
        $this->assertSame(0, Money::compare(1.10, 1.10));
        $this->assertSame(1, Money::compare(1.11, 1.10));
        $this->assertSame(-1, Money::compare(1.09, 1.10));
    }
}
