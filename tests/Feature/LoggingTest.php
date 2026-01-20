<?php

use App\Models\User;
use App\Models\Solicitacoes;
use App\Models\PaymentEvent;
use App\Services\BalanceService;
use App\Services\PaymentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    Log::spy();
});

test('BalanceService registra logs de incremento', function () {
    $user = User::factory()->create(['saldo' => 100.00]);
    $balanceService = app(BalanceService::class);
    
    $balanceService->incrementBalance($user, 50.00, 'saldo');
    
    Log::shouldHaveReceived('info')
        ->with('Saldo incrementado com sucesso', \Mockery::on(function ($data) {
            return isset($data['user_id']) 
                && isset($data['amount']) 
                && isset($data['balance_before']) 
                && isset($data['balance_after']);
        }));
});

test('BalanceService registra logs de decremento', function () {
    $user = User::factory()->create(['saldo' => 100.00]);
    $balanceService = app(BalanceService::class);
    
    $balanceService->decrementBalance($user, 30.00, 'saldo');
    
    Log::shouldHaveReceived('info')
        ->with('Saldo decrementado com sucesso', \Mockery::on(function ($data) {
            return isset($data['user_id']) 
                && isset($data['amount']) 
                && isset($data['balance_before']) 
                && isset($data['balance_after']);
        }));
});

test('PaymentProcessingService registra eventos de pagamento', function () {
    $user = User::factory()->create(['saldo' => 1000.00]);
    
    $cashin = Solicitacoes::factory()->create([
        'user_id' => $user->username,
        'status' => 'WAITING_FOR_APPROVAL',
        'amount' => 100.00,
        'deposito_liquido' => 95.00,
    ]);
    
    $paymentService = app(PaymentProcessingService::class);
    $paymentService->processPaymentReceived($cashin);
    
    // Verificar que evento foi registrado no banco
    $event = PaymentEvent::where('transaction_id', $cashin->id)->first();
    
    expect($event)->not->toBeNull();
    expect($event->event_type)->toBe('PAYMENT_RECEIVED');
    expect($event->user_id)->toBe($user->id);
    expect((float) $event->amount)->toBe(100.00);
    expect((float) $event->amount_credited)->toBe(95.00);
    expect((float) $event->balance_before)->toBe(1000.00);
    expect((float) $event->balance_after)->toBe(1095.00);
});

test('WebhookService registra logs de processamento', function () {
    $webhookService = app(\App\Services\WebhookService::class);
    
    $request = \Illuminate\Http\Request::create('/webhook', 'POST', [
        'type' => 'order.paid',
        'data' => ['id' => 'test-logging']
    ]);
    
    Log::spy();
    
    $webhookService->processWebhook($request, 'pagarme', function () {
        return response()->json(['status' => 'success']);
    });
    
    Log::shouldHaveReceived('info')
        ->with('Webhook processado com sucesso', \Mockery::type('array'));
});

test('PaymentEventService pode reconstruir saldo a partir de eventos', function () {
    $user = User::factory()->create(['saldo' => 1000.00]);
    
    $eventService = app(\App\Services\PaymentEventService::class);
    
    // Criar eventos manualmente
    PaymentEvent::create([
        'event_type' => 'PAYMENT_RECEIVED',
        'transaction_id' => 1,
        'transaction_type' => 'deposit',
        'user_id' => $user->id,
        'amount' => 100.00,
        'amount_credited' => 95.00,
        'amount_debited' => null,
        'balance_before' => 1000.00,
        'balance_after' => 1095.00,
        'metadata' => [],
    ]);
    
    PaymentEvent::create([
        'event_type' => 'PAYMENT_SENT',
        'transaction_id' => 2,
        'transaction_type' => 'withdrawal',
        'user_id' => $user->id,
        'amount' => 50.00,
        'amount_credited' => null,
        'amount_debited' => 55.00,
        'balance_before' => 1095.00,
        'balance_after' => 1040.00,
        'metadata' => [],
    ]);
    
    // Reconstruir saldo
    $reconstructedBalance = $eventService->reconstructBalance($user);
    
    // Saldo reconstruído: 0 (inicial) + 95 - 55 = 40
    // O método reconstrói a partir de 0, não do saldo inicial
    expect((float) $reconstructedBalance)->toBe(40.00);
});
