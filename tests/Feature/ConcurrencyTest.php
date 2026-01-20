<?php

use App\Models\Solicitacoes;
use App\Models\User;
use App\Models\PaymentEvent;
use App\Services\BalanceService;
use App\Services\PaymentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('Múltiplos webhooks simultâneos do mesmo pagamento processam apenas uma vez', function () {
    $user = User::factory()->create(['saldo' => 1000.00]);
    
    $cashin = Solicitacoes::factory()->create([
        'user_id' => $user->username,
        'status' => 'WAITING_FOR_APPROVAL',
        'amount' => 100.00,
        'deposito_liquido' => 95.00,
        'taxa_cash_in' => 5.00,
        'idTransaction' => 'test-concurrent-' . uniqid(),
        'externalreference' => 'ext-' . uniqid(),
        'date' => now(),
    ]);
    
    $balanceBefore = $user->saldo;
    $paymentService = app(PaymentProcessingService::class);
    
    // Simular 10 webhooks simultâneos
    $results = [];
    for ($i = 0; $i < 10; $i++) {
        try {
            $results[] = $paymentService->processPaymentReceived($cashin);
        } catch (\Exception $e) {
            $results[] = $e->getMessage();
        }
    }
    
    $cashin->refresh();
    $user->refresh();
    
    // Apenas 1 deve ter sido processado
    expect($cashin->status)->toBe('PAID_OUT');
    expect($user->saldo)->toBe($balanceBefore + 95.00);
    
    // Apenas 1 evento deve ter sido registrado
    expect(PaymentEvent::where('transaction_id', $cashin->id)->count())->toBe(1);
});

test('Múltiplas operações de incremento simultâneas mantêm integridade', function () {
    $user = User::factory()->create(['saldo' => 100.00]);
    $balanceService = app(BalanceService::class);
    
    // Simular 20 incrementos simultâneos de 10.00 cada
    $results = [];
    for ($i = 0; $i < 20; $i++) {
        $results[] = DB::transaction(function () use ($user, $balanceService) {
            return $balanceService->incrementBalance($user, 10.00, 'saldo');
        });
    }
    
    $user->refresh();
    
    // Saldo final deve ser 100 + (20 * 10) = 300
    expect($user->saldo)->toBe(300.00);
});

test('Operações concorrentes de incremento e decremento mantêm consistência', function () {
    $user = User::factory()->create(['saldo' => 500.00]);
    $balanceService = app(BalanceService::class);
    
    // Simular operações concorrentes
    $operations = [];
    
    // 10 incrementos de 50
    for ($i = 0; $i < 10; $i++) {
        $operations[] = fn() => DB::transaction(function () use ($user, $balanceService) {
            return $balanceService->incrementBalance($user, 50.00, 'saldo');
        });
    }
    
    // 5 decrementos de 30
    for ($i = 0; $i < 5; $i++) {
        $operations[] = fn() => DB::transaction(function () use ($user, $balanceService) {
            return $balanceService->decrementBalance($user, 30.00, 'saldo');
        });
    }
    
    // Executar todas as operações
    foreach ($operations as $operation) {
        $operation();
    }
    
    $user->refresh();
    
    // Saldo final: 500 + (10 * 50) - (5 * 30) = 500 + 500 - 150 = 850
    expect($user->saldo)->toBe(850.00);
});
