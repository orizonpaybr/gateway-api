<?php

namespace Tests\Feature\Paytler;

use App\Models\Solicitacoes;
use App\Models\User;
use App\Services\Paytler\PaytlerCashInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dedup de cash-in Paytler por PAGAMENTO (txid).
 *
 * A Paytler amarra vários charges/QRs ao MESMO txid — pagar 1 marca todos COMPLETED.
 * Sem dedup, o mesmo R$ credita N vezes (cliente paga 5, recebe 20). Este teste
 * garante que um pagamento (txid) credita NO MÁXIMO um depósito.
 */
class PaytlerCashInDedupTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function mesmo_txid_credita_apenas_um_deposito(): void
    {
        User::factory()->create(['user_id' => 'lojaX', 'username' => 'lojaX', 'saldo' => 0]);

        $d1 = Solicitacoes::factory()->pending()->create([
            'user_id' => 'lojaX', 'amount' => 5.00, 'deposito_liquido' => 4.90,
            'executor_ordem' => 'paytler', 'idTransaction' => 'uuid-charge-1',
            'status' => 'WAITING_FOR_APPROVAL',
        ]);
        $d2 = Solicitacoes::factory()->pending()->create([
            'user_id' => 'lojaX', 'amount' => 5.00, 'deposito_liquido' => 4.90,
            'executor_ordem' => 'paytler', 'idTransaction' => 'uuid-charge-2',
            'status' => 'WAITING_FOR_APPROVAL',
        ]);

        $svc = app(PaytlerCashInService::class);

        // Dois charges do MESMO pagamento (txid). O 2º NÃO pode creditar.
        $o1 = $svc->creditIfNotDuplicate($d1, 'TXID-SHARED', null);
        $o2 = $svc->creditIfNotDuplicate($d2, 'TXID-SHARED', null);

        $this->assertSame('credited', $o1);
        $this->assertSame('duplicate', $o2);

        $this->assertSame('PAID_OUT', $d1->fresh()->status);
        $this->assertSame('CANCELLED', $d2->fresh()->status, 'Charge duplicado deve ser anulado, não creditado');

        $this->assertSame('TXID-SHARED', (string) $d1->fresh()->provider_payment_id);
        $this->assertSame('TXID-SHARED', (string) $d2->fresh()->provider_payment_id);
    }

    /** @test */
    public function txids_diferentes_creditam_normalmente(): void
    {
        User::factory()->create(['user_id' => 'lojaY', 'username' => 'lojaY', 'saldo' => 0]);

        $d1 = Solicitacoes::factory()->pending()->create([
            'user_id' => 'lojaY', 'amount' => 5.00, 'deposito_liquido' => 4.90,
            'executor_ordem' => 'paytler', 'idTransaction' => 'uuid-a', 'status' => 'WAITING_FOR_APPROVAL',
        ]);
        $d2 = Solicitacoes::factory()->pending()->create([
            'user_id' => 'lojaY', 'amount' => 5.00, 'deposito_liquido' => 4.90,
            'executor_ordem' => 'paytler', 'idTransaction' => 'uuid-b', 'status' => 'WAITING_FOR_APPROVAL',
        ]);

        $svc = app(PaytlerCashInService::class);
        // Pagamentos DIFERENTES (txids distintos) — os dois creditam.
        $this->assertSame('credited', $svc->creditIfNotDuplicate($d1, 'TXID-A', null));
        $this->assertSame('credited', $svc->creditIfNotDuplicate($d2, 'TXID-B', null));
        $this->assertSame('PAID_OUT', $d1->fresh()->status);
        $this->assertSame('PAID_OUT', $d2->fresh()->status);
    }
}
