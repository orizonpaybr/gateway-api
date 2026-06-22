<?php

namespace Tests\Feature\Webhooks;

use App\Models\Solicitacoes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Helpers\AuthTestHelper;
use Tests\Feature\Helpers\TransactionTestHelper;
use Tests\TestCase;

/**
 * Cobre o fluxo de infração Pix (MED) recebido via webhook da API Contas Treeal:
 *  - OPEN: registra a infração e bloqueia o depósito (MEDIATION), sem mexer no saldo.
 *  - CLOSED + AGREED (fraude confirmada): marca REFUNDED e debita o saldo do lojista.
 *  - CANCELLED / CLOSED + DISAGREED: libera o bloqueio (volta para COMPLETED).
 *  - Idempotência: o mesmo id de infração não duplica registro.
 *
 * Substitui o ambiente de homologação: garante que o comportamento está correto antes de prod.
 */
class TreealContasInfractionWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sem header de auth e sem credenciais: o handler não tenta buscar o detalhe na API,
        // usando apenas o endToEndId que vem no payload.
        config([
            'treeal_contas.webhook_auth_header' => '',
            'treeal_contas.webhook_auth_value' => '',
            'treeal_contas.client_id' => '',
            'treeal_contas.client_secret' => '',
        ]);
    }

    private function createTreealDeposit(string $username, string $e2e, string $status, float $liquido = 97.50, float $amount = 100.00): Solicitacoes
    {
        return TransactionTestHelper::createSolicitacao([
            'user_id' => $username,
            'executor_ordem' => 'treeal',
            'adquirente_ref' => 'treeal',
            'status' => $status,
            'end_to_end' => $e2e,
            'amount' => $amount,
            'deposito_liquido' => $liquido,
            'callback' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $dataOverrides
     */
    private function postInfraction(array $dataOverrides): \Illuminate\Testing\TestResponse
    {
        $data = array_merge([
            'id' => 'inf-'.uniqid(),
            'status' => 'OPEN',
            'type' => 'FRAUD',
            'reportedBy' => 'DEBITED_PARTICIPANT',
            'reportDetails' => 'Cliente alega fraude.',
            'creationDate' => now()->toIso8601String(),
            'lastModificationDate' => now()->toIso8601String(),
            'transactionAmount' => ['currency' => 'BRL', 'amount' => 100.00],
        ], $dataOverrides);

        return $this->postJson('/treeal/contas/webhook', [
            'type' => 'INFRACTION',
            'data' => $data,
        ]);
    }

    public function test_open_infraction_blocks_deposit_and_records(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'inf_open_'.uniqid(),
            'email' => 'inf_open_'.uniqid().'@example.com',
            'saldo' => 200.00,
        ]);

        $deposit = $this->createTreealDeposit($user->username, 'E_OPEN_1', 'COMPLETED');

        $response = $this->postInfraction([
            'id' => 'inf-open-1',
            'status' => 'OPEN',
            'endToEndId' => 'E_OPEN_1',
        ]);

        $response->assertOk()->assertJson(['received' => true, 'processed' => true]);

        // Depósito bloqueado (valor sai do disponível para saque).
        $this->assertSame('MEDIATION', $deposit->fresh()->status);

        // Saldo não muda no bloqueio.
        $this->assertEquals(200.00, (float) $user->fresh()->saldo);

        // Registro da infração criado e vinculado ao lojista.
        $this->assertDatabaseHas('pix_infracoes', [
            'provider_infraction_id' => 'inf-open-1',
            'user_id' => $user->username,
            'status' => 'PENDENTE',
            'end_to_end' => 'E_OPEN_1',
        ]);
    }

    public function test_closed_agreed_refunds_and_debits_balance(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'inf_fraud_'.uniqid(),
            'email' => 'inf_fraud_'.uniqid().'@example.com',
            'saldo' => 200.00,
        ]);

        $deposit = $this->createTreealDeposit($user->username, 'E_FRAUD_1', 'COMPLETED', liquido: 97.50);

        $response = $this->postInfraction([
            'id' => 'inf-fraud-1',
            'status' => 'CLOSED',
            'analysisResult' => 'AGREED',
            'endToEndId' => 'E_FRAUD_1',
        ]);

        $response->assertOk()->assertJson(['received' => true, 'processed' => true]);

        // Devolução efetivada: depósito estornado e saldo debitado pelo líquido creditado.
        $this->assertSame('REFUNDED', $deposit->fresh()->status);
        $this->assertEquals(102.50, (float) $user->fresh()->saldo);

        $this->assertDatabaseHas('pix_infracoes', [
            'provider_infraction_id' => 'inf-fraud-1',
            'status' => 'RESOLVIDA',
        ]);
    }

    public function test_cancelled_releases_hold(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'inf_cancel_'.uniqid(),
            'email' => 'inf_cancel_'.uniqid().'@example.com',
            'saldo' => 50.00,
        ]);

        // Depósito já bloqueado por uma infração anterior.
        $deposit = $this->createTreealDeposit($user->username, 'E_CANCEL_1', 'MEDIATION');

        $response = $this->postInfraction([
            'id' => 'inf-cancel-1',
            'status' => 'CANCELLED',
            'endToEndId' => 'E_CANCEL_1',
        ]);

        $response->assertOk()->assertJson(['received' => true, 'processed' => true]);

        // Bloqueio liberado: volta a contar como concluído (disponível para saque).
        $this->assertSame('COMPLETED', $deposit->fresh()->status);
        $this->assertEquals(50.00, (float) $user->fresh()->saldo);
    }

    public function test_closed_disagreed_releases_hold_without_debit(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'inf_win_'.uniqid(),
            'email' => 'inf_win_'.uniqid().'@example.com',
            'saldo' => 75.00,
        ]);

        $deposit = $this->createTreealDeposit($user->username, 'E_WIN_1', 'MEDIATION');

        $response = $this->postInfraction([
            'id' => 'inf-win-1',
            'status' => 'CLOSED',
            'analysisResult' => 'DISAGREED',
            'endToEndId' => 'E_WIN_1',
        ]);

        $response->assertOk()->assertJson(['received' => true, 'processed' => true]);

        $this->assertSame('COMPLETED', $deposit->fresh()->status);
        $this->assertEquals(75.00, (float) $user->fresh()->saldo);
    }

    public function test_idempotent_upsert_does_not_duplicate(): void
    {
        $user = AuthTestHelper::createTestUser([
            'username' => 'inf_idem_'.uniqid(),
            'email' => 'inf_idem_'.uniqid().'@example.com',
            'saldo' => 200.00,
        ]);

        $this->createTreealDeposit($user->username, 'E_IDEM_1', 'COMPLETED');

        $this->postInfraction(['id' => 'inf-idem-1', 'status' => 'OPEN', 'endToEndId' => 'E_IDEM_1'])->assertOk();
        $this->postInfraction(['id' => 'inf-idem-1', 'status' => 'ACKNOWLEDGED', 'endToEndId' => 'E_IDEM_1'])->assertOk();

        $count = DB::table('pix_infracoes')->where('provider_infraction_id', 'inf-idem-1')->count();
        $this->assertSame(1, $count);

        // Última atualização prevalece (ACKNOWLEDGED → EM_ANALISE).
        $this->assertDatabaseHas('pix_infracoes', [
            'provider_infraction_id' => 'inf-idem-1',
            'status' => 'EM_ANALISE',
        ]);
    }

    public function test_infraction_without_known_deposit_is_acknowledged(): void
    {
        $response = $this->postInfraction([
            'id' => 'inf-unknown-1',
            'status' => 'OPEN',
            'endToEndId' => 'E_DOES_NOT_EXIST',
        ]);

        $response->assertOk()
            ->assertJson(['received' => true, 'processed' => false, 'reason' => 'deposit_not_found']);
    }
}
