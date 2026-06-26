<?php

namespace Tests\Unit\Services;

use App\Models\Solicitacoes;
use App\Services\GamificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Helpers\AuthTestHelper;
use Tests\Feature\Helpers\TransactionTestHelper;
use Tests\TestCase;

class GamificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_journey_total_excludes_mediation_and_refunded_deposits(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'journey_'.uniqid(),
            'email' => 'journey_'.uniqid().'@example.com',
            'volume_transacional' => 999999.00,
        ]);

        TransactionTestHelper::createSolicitacao([
            'user_id' => $user->username,
            'status' => 'COMPLETED',
            'amount' => 1000.00,
        ]);
        TransactionTestHelper::createSolicitacao([
            'user_id' => $user->username,
            'status' => 'MEDIATION',
            'amount' => 5000.00,
        ]);
        TransactionTestHelper::createSolicitacao([
            'user_id' => $user->username,
            'status' => 'REFUNDED',
            'amount' => 3000.00,
        ]);

        $result = app(GamificationService::class)->meuNivel($user);

        $this->assertSame(1000.0, (float) $result['total_depositos']);
    }
}
