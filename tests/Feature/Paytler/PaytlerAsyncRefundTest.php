<?php

namespace Tests\Feature\Paytler;

use App\Jobs\ReconcilePaytlerRefundsJob;
use App\Models\Solicitacoes;
use App\Models\User;
use App\Services\Paytler\PaytlerPixAcquirerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Estorno ASSÍNCRONO Paytler: a devolução (reverse-pix-in) só finaliza (marca REFUNDED +
 * DECREMENTA saldo) quando a adquirente confirma REFUNDED. Se a devolução FALHA, o depósito
 * volta pra PAID_OUT e o saldo NÃO é tocado — sem isso, um estorno que falha na adquirente
 * tirava dinheiro do lojista sem devolver nada ao pagador.
 */
class PaytlerAsyncRefundTest extends TestCase
{
    use RefreshDatabase;

    private function fakeAcquirer(array $statusResult): void
    {
        $mock = Mockery::mock(PaytlerPixAcquirerService::class);
        $mock->shouldReceive('isActive')->andReturnTrue();
        $mock->shouldReceive('getRefundStatus')->andReturn($statusResult);
        $this->app->instance(PaytlerPixAcquirerService::class, $mock);
    }

    private function depositoEmEstorno(float $saldo = 100.0): Solicitacoes
    {
        User::factory()->create(['user_id' => 'lojaR', 'username' => 'lojaR', 'saldo' => $saldo]);

        return Solicitacoes::factory()->create([
            'user_id' => 'lojaR', 'amount' => 5.00, 'deposito_liquido' => 4.90,
            'executor_ordem' => 'paytler', 'idTransaction' => 'uuid-charge-r',
            'end_to_end' => 'E1200abc', 'status' => 'REFUND_PROCESSING',
            'refund_provider_id' => 'REV-1',
        ]);
    }

    /** @test */
    public function estorno_confirmado_marca_refunded_e_decrementa_saldo(): void
    {
        Queue::fake();
        Http::fake();
        $this->fakeAcquirer(['success' => true, 'status' => 'REFUNDED']);
        $d = $this->depositoEmEstorno(100.0);

        app(ReconcilePaytlerRefundsJob::class)->handle();

        $this->assertSame('REFUNDED', $d->fresh()->status);
        $this->assertLessThan(100.0, (float) User::where('user_id', 'lojaR')->first()->saldo,
            'estorno confirmado deve remover o valor do saldo do lojista');
    }

    /** @test */
    public function estorno_que_falha_reverte_para_paid_out_sem_tocar_saldo(): void
    {
        Queue::fake();
        Http::fake();
        $this->fakeAcquirer(['success' => true, 'status' => 'FAILED', 'provider_status' => 'ERROR']);
        $d = $this->depositoEmEstorno(100.0);

        app(ReconcilePaytlerRefundsJob::class)->handle();

        $this->assertSame('PAID_OUT', $d->fresh()->status, 'estorno que falha volta pro estado pago');
        $this->assertSame(100.0, (float) User::where('user_id', 'lojaR')->first()->saldo,
            'estorno que falha NÃO pode tirar do saldo (senão perde dinheiro do lojista)');
    }

    /** @test */
    public function estorno_ainda_processando_mantem_refund_processing(): void
    {
        Queue::fake();
        Http::fake();
        $this->fakeAcquirer(['success' => true, 'status' => 'PROCESSING']);
        $d = $this->depositoEmEstorno(100.0);

        app(ReconcilePaytlerRefundsJob::class)->handle();

        $this->assertSame('REFUND_PROCESSING', $d->fresh()->status);
        $this->assertSame(100.0, (float) User::where('user_id', 'lojaR')->first()->saldo);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
