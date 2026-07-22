<?php

namespace Tests\Unit\Services;

use App\Models\Solicitacoes;
use App\Services\FinancialService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Cobre o bug: adquirente_ref varia por nominal (ex: várias contas FluxPayments
 * com credenciais próprias), então o gate de estorno precisa usar executor_ordem
 * (fixo por provider), não adquirente_ref.
 */
class FinancialServiceRefundEligibilityTest extends TestCase
{
    private function podeEstornar(Solicitacoes $deposit): bool
    {
        $method = new ReflectionMethod(FinancialService::class, 'depositPodeEstornar');
        $method->setAccessible(true);

        return $method->invoke(app(FinancialService::class), $deposit);
    }

    /** @test */
    public function permite_estorno_para_nominal_fluxpayments_com_adquirente_ref_diferente(): void
    {
        $deposit = new Solicitacoes([
            'executor_ordem' => 'fluxpayments',
            'adquirente_ref' => 'fluxpayments_santa',
            'status' => 'PAID_OUT',
            'idTransaction' => '019f8be2-6502-7932-b0c6-b0241e7aa874',
        ]);

        $this->assertTrue($this->podeEstornar($deposit));
    }

    /** @test */
    public function permite_estorno_para_nominal_fluxpayments_padrao(): void
    {
        $deposit = new Solicitacoes([
            'executor_ordem' => 'fluxpayments',
            'adquirente_ref' => 'fluxpayments',
            'status' => 'PAID_OUT',
            'idTransaction' => '019f8be5-6f5a-7802-9bb8-ffbd8c7a71be',
        ]);

        $this->assertTrue($this->podeEstornar($deposit));
    }

    /** @test */
    public function nega_estorno_para_provider_nao_suportado(): void
    {
        $deposit = new Solicitacoes([
            'executor_ordem' => 'pagarme',
            'adquirente_ref' => 'Pagarme',
            'status' => 'PAID_OUT',
            'idTransaction' => 'abc123',
        ]);

        $this->assertFalse($this->podeEstornar($deposit));
    }
}
