<?php

namespace Tests\Unit\Services\Fyhub;

use App\Services\Fyhub\FyhubAuthService;
use App\Services\Fyhub\FyhubPixAcquirerService;
use App\Services\FyhubContas\FyhubContasPixOutService;
use Tests\TestCase;

class FyhubInitialPayoutStatusTest extends TestCase
{
    private function service(): FyhubPixAcquirerService
    {
        return new FyhubPixAcquirerService(
            $this->createMock(FyhubAuthService::class),
            $this->createMock(FyhubContasPixOutService::class),
        );
    }

    public function test_resolve_initial_payout_status_promotes_processing_when_e2e_present(): void
    {
        $this->assertSame(
            'COMPLETED',
            $this->service()->resolveInitialPayoutStatus('PROCESSING', 'E4397869720260519000408508c56c02')
        );
    }

    public function test_resolve_initial_payout_status_keeps_processing_without_e2e(): void
    {
        $this->assertSame('PROCESSING', $this->service()->resolveInitialPayoutStatus('PENDING', null));
    }
}
