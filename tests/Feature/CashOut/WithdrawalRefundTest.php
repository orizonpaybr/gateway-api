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
 *  - FAILED / CANCELLED após débito: ESTORNA (saldo volta).
 *  - Lookup do dono funciona quando user_id na linha é o username.
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

        // Saldo NÃO deve ser alterado (saque concluído com sucesso).
        $this->assertEquals(50.00, (float) $user->fresh()->saldo);
    }

    public function test_failed_apos_processing_estorna_saldo(): void
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

        // Saldo deve voltar: 50 + 102.50 debitado = 152.50
        $this->assertEquals(152.50, (float) $user->fresh()->saldo);
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

        $this->assertEquals('CANCELLED', $withdrawal->fresh()->status);
        $this->assertEquals(112.50, (float) $user->fresh()->saldo);
    }

    public function test_estorna_split_afiliado_e_principal(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'split_'.uniqid(),
            'email' => 'split_'.uniqid().'@example.com',
            'saldo' => 0.0,
            'saldo_afiliado' => 0.0,
        ]);

        // Débito de 102.50 dividido: 40 afiliado + 62.50 principal
        $withdrawal = $this->createDebitedProcessingWithdrawal(
            $user->user_id,
            debitoPrincipal: 62.50,
            debitoAfiliado: 40.00,
        );

        $this->applier()->applyTerminalStatusIfNeeded($withdrawal, 'FAILED');

        $fresh = $user->fresh();
        $this->assertEquals(62.50, (float) $fresh->saldo);
        $this->assertEquals(40.00, (float) $fresh->saldo_afiliado);
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

        // Estornado uma única vez: 0 + 102.50
        $this->assertEquals(102.50, (float) $user->fresh()->saldo);
    }
}
