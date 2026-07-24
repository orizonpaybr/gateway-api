<?php

namespace Tests\Feature\Balance;

use App\Models\BalanceLedgerEntry;
use App\Services\AdminUserService;
use App\Services\BalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Feature\Helpers\AuthTestHelper;
use Tests\TestCase;

class BalanceLedgerAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_adjust_grava_ledger(): void
    {
        $admin = AuthTestHelper::createTestUser([
            'username' => 'admin_'.uniqid(),
            'email' => 'admin_'.uniqid().'@example.com',
            'permission' => 1,
            'saldo' => 0,
        ]);
        Auth::login($admin);

        $user = AuthTestHelper::createTestUser([
            'username' => 'cli_'.uniqid(),
            'email' => 'cli_'.uniqid().'@example.com',
            'saldo' => 100.00,
        ]);

        app(AdminUserService::class)->adjustBalance((int) $user->id, 50.00, 'add');

        $entry = BalanceLedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('reason', 'admin_adjust')
            ->first();

        $this->assertNotNull($entry);
        $this->assertEquals(50.0, (float) $entry->delta);
        $this->assertEquals(100.0, (float) $entry->balance_before);
        $this->assertEquals(150.0, (float) $entry->balance_after);
        $this->assertEquals($admin->id, (int) $entry->actor_id);
    }

    public function test_affiliate_credit_grava_ledger(): void
    {
        $affiliate = AuthTestHelper::createTestUser([
            'username' => 'aff_'.uniqid(),
            'email' => 'aff_'.uniqid().'@example.com',
            'saldo' => 0,
            'saldo_afiliado' => 10.00,
        ]);

        app(BalanceService::class)->incrementBalance($affiliate, 1.50, 'saldo_afiliado', [
            'reason' => 'affiliate_cash_out',
            'source' => 'test',
            'ref_type' => 'solicitacoes_cash_out',
            'ref_id' => 999,
        ]);

        $entry = BalanceLedgerEntry::query()
            ->where('user_id', $affiliate->id)
            ->where('reason', 'affiliate_cash_out')
            ->first();

        $this->assertNotNull($entry);
        $this->assertEquals('saldo_afiliado', $entry->field);
        $this->assertEquals(1.5, (float) $entry->delta);
        $this->assertEquals(11.5, (float) $entry->balance_after);
    }
}
