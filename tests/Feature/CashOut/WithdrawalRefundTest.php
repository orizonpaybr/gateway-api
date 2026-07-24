<?php

namespace Tests\Feature\CashOut;

use App\Models\SolicitacoesCashOut;
use App\Services\CashOut\CashOutOutcomeApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Helpers\AuthTestHelper;
use Tests\TestCase;

/**
 * Garante o comportamento de saldo no status terminal de Pix Out (núcleo compartilhado
 * por API, dashboard e webhooks via CashOutOutcomeApplier):
 *  - COMPLETED: NÃO estorna (saque funcionou — saldo permanece debitado).
 *  - FAILED / CANCELLED / REFUNDED após débito: ESTORNA (saldo volta) e zera debito_*.
 *  - PENDING sem débito: NÃO estorna (evita race webhook antes do decremento).
 *  - Idempotência: aplicar status terminal duas vezes não estorna em dobro.
 */
class WithdrawalRefundTest extends TestCase
{
    use RefreshDatabase;

    private function applier(): CashOutOutcomeApplier
    {
        return app(CashOutOutcomeApplier::class);
    }

    /**
     * Cria um saque já debitado em PROCESSING (estado pós-aceite da adquirente).
     * Espelha o débito que SaqueController/PixKeyController gravam.
     */
    private function createDebitedProcessingWithdrawal(
        string $userIdValue,
        float $amount = 100.00,
        float $taxa = 2.50,
        float $debitoPrincipal = 102.50,
        float $debitoAfiliado = 0.0,
    ): SolicitacoesCashOut {
        return SolicitacoesCashOut::create([
            'user_id' => $userIdValue,
            'idTransaction' => 'TXN_'.uniqid(),
            'externalreference' => 'EXT_'.uniqid(),
            'amount' => $amount,
            'cash_out_liquido' => $amount - $taxa,
            'taxa_cash_out' => $taxa,
            'valor_total_descontado' => round($debitoPrincipal + $debitoAfiliado, 4),
            'debito_saldo_principal' => round($debitoPrincipal, 4),
            'debito_saldo_afiliado' => round($debitoAfiliado, 4),
            'status' => 'PROCESSING',
            'date' => now(),
            'pix' => 'test@example.com',
            'pixkey' => 'email',
            'type' => 'PIX',
            'beneficiaryname' => '',
            'beneficiarydocument' => '',
            'descricao_transacao' => 'AUTOMATICO',
            'executor_ordem' => 'simpay',
            'callback' => null,
        ]);
    }

    public function test_completed_nao_estorna_saldo_saque_funcionou_normal(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'happy_'.uniqid(),
            'email' => 'happy_'.uniqid().'@example.com',
            'saldo' => 50.00,
            'saldo_afiliado' => 0.0,
        ]);

        $withdrawal = $this->createDebitedProcessingWithdrawal($user->user_id);

        $applied = $this->applier()->applyTerminalStatusIfNeeded($withdrawal, 'COMPLETED');

        $this->assertTrue($applied);
        $withdrawal->refresh();
        $this->assertEquals('COMPLETED', $withdrawal->status);

        $this->assertEquals(50.00, (float) $user->fresh()->saldo);
        $this->assertEquals(102.50, (float) $withdrawal->debito_saldo_principal);
    }

    public function test_failed_apos_processing_estorna_saldo_e_zera_debito(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'fail_'.uniqid(),
            'email' => 'fail_'.uniqid().'@example.com',
            'saldo' => 50.00,
            'saldo_afiliado' => 0.0,
        ]);

        $withdrawal = $this->createDebitedProcessingWithdrawal($user->user_id);

        $applied = $this->applier()->applyTerminalStatusIfNeeded($withdrawal, 'FAILED');

        $this->assertTrue($applied);
        $withdrawal->refresh();
        $this->assertEquals('FAILED', $withdrawal->status);

        $this->assertEquals(152.50, (float) $user->fresh()->saldo);
        $this->assertEquals(0.0, (float) $withdrawal->debito_saldo_principal);
        $this->assertEquals(0.0, (float) $withdrawal->debito_saldo_afiliado);
    }

    public function test_cancelled_apos_processing_estorna_saldo(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'cancel_'.uniqid(),
            'email' => 'cancel_'.uniqid().'@example.com',
            'saldo' => 10.00,
            'saldo_afiliado' => 0.0,
        ]);

        $withdrawal = $this->createDebitedProcessingWithdrawal($user->user_id);

        $this->applier()->applyTerminalStatusIfNeeded($withdrawal, 'CANCELLED');

        $fresh = $withdrawal->fresh();
        $this->assertEquals('CANCELLED', $fresh->status);
        $this->assertEquals(112.50, (float) $user->fresh()->saldo);
        $this->assertEquals(0.0, (float) $fresh->debito_saldo_principal);
    }

    public function test_refunded_apos_processing_estorna_saldo(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'ref_'.uniqid(),
            'email' => 'ref_'.uniqid().'@example.com',
            'saldo' => 0.0,
            'saldo_afiliado' => 0.0,
        ]);

        $withdrawal = $this->createDebitedProcessingWithdrawal($user->user_id);

        $this->applier()->applyTerminalStatusIfNeeded($withdrawal, 'REFUNDED');

        $fresh = $withdrawal->fresh();
        $this->assertEquals('REFUNDED', $fresh->status);
        $this->assertEquals(102.50, (float) $user->fresh()->saldo);
        $this->assertEquals(0.0, (float) $fresh->debito_saldo_principal);
    }

    public function test_pending_sem_debito_nao_estorna_ao_falhar(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'race_'.uniqid(),
            'email' => 'race_'.uniqid().'@example.com',
            'saldo' => 100.00,
            'saldo_afiliado' => 0.0,
        ]);

        $withdrawal = SolicitacoesCashOut::create([
            'user_id' => $user->user_id,
            'idTransaction' => 'TXN_'.uniqid(),
            'externalreference' => 'EXT_'.uniqid(),
            'amount' => 100.00,
            'cash_out_liquido' => 98.50,
            'taxa_cash_out' => 1.50,
            'valor_total_descontado' => 100.00,
            'debito_saldo_principal' => null,
            'debito_saldo_afiliado' => null,
            'status' => 'PENDING',
            'date' => now(),
            'pix' => 'test@example.com',
            'pixkey' => 'email',
            'type' => 'PIX',
            'beneficiaryname' => '',
            'beneficiarydocument' => '',
            'descricao_transacao' => 'AUTOMATICO',
            'executor_ordem' => 'fluxpayments',
            'callback' => null,
        ]);

        $this->applier()->applyTerminalStatusIfNeeded($withdrawal, 'FAILED');

        $this->assertEquals('FAILED', $withdrawal->fresh()->status);
        $this->assertEquals(100.00, (float) $user->fresh()->saldo);
    }

    public function test_estorna_split_afiliado_e_principal(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'split_'.uniqid(),
            'email' => 'split_'.uniqid().'@example.com',
            'saldo' => 0.0,
            'saldo_afiliado' => 0.0,
        ]);

        $withdrawal = $this->createDebitedProcessingWithdrawal(
            $user->user_id,
            debitoPrincipal: 62.50,
            debitoAfiliado: 40.00,
        );

        $this->applier()->applyTerminalStatusIfNeeded($withdrawal, 'FAILED');

        $fresh = $user->fresh();
        $this->assertEquals(62.50, (float) $fresh->saldo);
        $this->assertEquals(40.00, (float) $fresh->saldo_afiliado);
        $this->assertEquals(0.0, (float) $withdrawal->fresh()->debito_saldo_principal);
        $this->assertEquals(0.0, (float) $withdrawal->fresh()->debito_saldo_afiliado);
    }

    /**
     * Invariante da reserva-antes-do-payout: a linha nasce PENDING já debitada, e se a
     * adquirente recusar o Pix Out o valor reservado volta integralmente. Sem isto, uma
     * recusa deixaria o cliente debitado sem PIX (o inverso do vazamento de jul/2026).
     */
    public function test_pending_com_debito_estorna_quando_adquirente_recusa(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'reserva_'.uniqid(),
            'email' => 'reserva_'.uniqid().'@example.com',
            'saldo' => 20.00,
            'saldo_afiliado' => 0.0,
        ]);

        $withdrawal = $this->createDebitedProcessingWithdrawal($user->user_id);
        $withdrawal->update(['status' => 'PENDING']);

        \App\Services\WithdrawalFailureRefundService::creditBackIfApplicable(
            $withdrawal->fresh(),
            'PENDING',
            'FAILED'
        );

        $this->assertEquals(122.50, (float) $user->fresh()->saldo);
        $this->assertEquals(0.0, (float) $withdrawal->fresh()->debito_saldo_principal);
        $this->assertEquals(0.0, (float) $withdrawal->fresh()->debito_saldo_afiliado);
    }

    public function test_idempotente_nao_estorna_em_dobro(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'idem_'.uniqid(),
            'email' => 'idem_'.uniqid().'@example.com',
            'saldo' => 0.0,
            'saldo_afiliado' => 0.0,
        ]);

        $withdrawal = $this->createDebitedProcessingWithdrawal($user->user_id);

        $first = $this->applier()->applyTerminalStatusIfNeeded($withdrawal, 'FAILED');
        $second = $this->applier()->applyTerminalStatusIfNeeded($withdrawal->fresh(), 'FAILED');

        $this->assertTrue($first);
        $this->assertFalse($second);

        $this->assertEquals(102.50, (float) $user->fresh()->saldo);
        $this->assertEquals(0.0, (float) $withdrawal->fresh()->debito_saldo_principal);
    }
}
