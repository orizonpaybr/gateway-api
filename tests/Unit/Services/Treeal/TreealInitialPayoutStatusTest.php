<?php

namespace Tests\Unit\Services\Treeal;

use App\Services\Treeal\TreealAuthService;
use App\Services\Treeal\TreealPixAcquirerService;
use App\Services\TreealContas\TreealContasAuthService;
use App\Services\TreealContas\TreealContasPixOutService;
use Tests\TestCase;

class TreealInitialPayoutStatusTest extends TestCase
{
    private function service(): TreealPixAcquirerService
    {
        return new TreealPixAcquirerService(
            $this->createMock(TreealAuthService::class),
            $this->createMock(TreealContasAuthService::class),
            $this->createMock(TreealContasPixOutService::class),
        );
    }

    public function test_resolve_initial_payout_status_keeps_processing_when_e2e_present(): void
    {
        $this->assertSame(
            'PROCESSING',
            $this->service()->resolveInitialPayoutStatus('PROCESSING', 'E4397869720260519000408508c56c02')
        );
    }

    public function test_resolve_initial_payout_status_keeps_processing_without_e2e(): void
    {
        $this->assertSame('PROCESSING', $this->service()->resolveInitialPayoutStatus('PENDING', null));
    }
}
