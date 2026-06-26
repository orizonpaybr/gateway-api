<?php

namespace Tests\Feature\Api;

use App\Services\FinancialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Helpers\AuthTestHelper;
use Tests\TestCase;

class FinancialWithdrawalsStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_withdrawals_stats_service_does_not_fail_on_cash_out_table(): void
    {
        Cache::flush();

        $user = AuthTestHelper::createTestUser([
            'username' => 'wd_stats_'.uniqid(),
            'email' => 'wd_stats_'.uniqid().'@example.com',
        ]);

        DB::table('solicitacoes_cash_out')->insert([
            'user_id' => $user->username,
            'externalreference' => 'ext-1',
            'amount' => 100.00,
            'beneficiaryname' => 'Test',
            'beneficiarydocument' => '00000000000',
            'pix' => 'cpf',
            'pixkey' => '00000000000',
            'date' => now(),
            'status' => 'COMPLETED',
            'type' => 'PIX',
            'taxa_cash_out' => 2.00,
            'cash_out_liquido' => 98.00,
            'executor_ordem' => 'treeal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stats = app(FinancialService::class)->getWithdrawalsStats('total');

        $this->assertIsArray($stats);
        $this->assertSame(1, $stats['total_saques_geral']);
        $this->assertSame(1, $stats['saques_aprovados_geral']);
    }

    public function test_withdrawals_stats_endpoint_returns_success_for_admin(): void
    {
        Cache::flush();

        $admin = AuthTestHelper::createTestUser([
            'username' => 'admin_fin_'.uniqid(),
            'email' => 'admin_fin_'.uniqid().'@example.com',
            'permission' => 3,
        ]);

        $token = AuthTestHelper::generateTestToken($admin);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/financial/withdrawals/stats?periodo=total')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_saques_geral',
                    'saques_aprovados_geral',
                    'valor_total_geral',
                    'lucro_total_geral',
                    'saques_pendentes_geral',
                ],
            ]);
    }
}
