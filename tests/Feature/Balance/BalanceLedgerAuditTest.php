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

    /**
     * Aprovação manual (admin) + webhook no mesmo depósito NÃO pode creditar duas vezes.
     * Antes o caminho admin creditava sem gravar PaymentEvent, então o webhook não via
     * o crédito anterior e recreditava. Agora ambos compartilham a idempotência do evento.
     */
    public function test_aprovacao_admin_mais_webhook_nao_credita_em_dobro(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'dep_'.uniqid(),
            'email' => 'dep_'.uniqid().'@example.com',
            'saldo' => 0,
        ]);

        $deposit = \App\Models\Solicitacoes::create([
            'user_id' => $user->user_id,
            'externalreference' => 'DEP_'.uniqid(),
            'idTransaction' => 'DEP_'.uniqid(),
            'amount' => 100.00,
            'deposito_liquido' => 90.00,
            'taxa_cash_in' => 10.00,
            'status' => 'WAITING_FOR_APPROVAL',
            'date' => now(),
            'client_name' => 'Cliente Teste',
            'client_document' => '12345678900',
            'client_email' => 'cliente@example.com',
            'client_telefone' => '11999999999',
            'adquirente_ref' => 'test',
            'descricao_transacao' => 'AUTOMATICO',
            'executor_ordem' => 'test',
            'paymentcode' => 'PC_'.uniqid(),
            'paymentCodeBase64' => 'base64',
            'qrcode_pix' => 'qr',
            'taxa_pix_cash_in_adquirente' => 0,
            'taxa_pix_cash_in_valor_fixo' => 0,
        ]);

        // 1) Admin aprova manualmente → credita uma vez e grava o evento.
        app(\App\Services\FinancialService::class)->updateDepositStatus($deposit->id, 'PAID_OUT');
        $this->assertEquals(90.00, (float) $user->fresh()->saldo);

        // 2) Webhook chega depois para o MESMO depósito → deve ver o evento e não recreditar.
        app(\App\Services\PaymentProcessingService::class)->processPaymentReceived($deposit->fresh());

        $this->assertEquals(90.00, (float) $user->fresh()->saldo, 'Saldo não pode dobrar entre admin e webhook');

        $eventos = \App\Models\PaymentEvent::where('transaction_id', $deposit->id)
            ->where('event_type', 'PAYMENT_RECEIVED')
            ->count();
        $this->assertEquals(1, $eventos, 'Deve haver exatamente um evento de crédito');
    }
}
