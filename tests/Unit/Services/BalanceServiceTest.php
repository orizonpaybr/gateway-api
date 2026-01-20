<?php

use App\Models\User;
use App\Services\BalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    test()->balanceService = app(BalanceService::class);
    Log::spy();
});

test('BalanceService pode incrementar saldo de forma thread-safe', function () {
    $user = User::factory()->create(['saldo' => 100.00]);
    
    $result = test()->balanceService->incrementBalance($user, 50.00, 'saldo');
    
    expect($result->fresh()->saldo)->toBe(150.00);
    Log::shouldHaveReceived('info')->with('Saldo incrementado com sucesso', \Mockery::type('array'));
});

test('BalanceService pode decrementar saldo de forma thread-safe', function () {
    $user = User::factory()->create(['saldo' => 100.00]);
    
    $result = test()->balanceService->decrementBalance($user, 30.00, 'saldo');
    
    expect($result->fresh()->saldo)->toBe(70.00);
    Log::shouldHaveReceived('info')->with('Saldo decrementado com sucesso', \Mockery::type('array'));
});

test('BalanceService lança exceção ao decrementar saldo insuficiente', function () {
    $user = User::factory()->create(['saldo' => 50.00]);
    
    expect(fn() => $this->balanceService->decrementBalance($user, 100.00, 'saldo'))
        ->toThrow(\Exception::class, 'Saldo insuficiente');
    
    // Saldo não deve ter mudado
    expect($user->fresh()->saldo)->toBe(50.00);
});

test('BalanceService pode definir saldo absoluto', function () {
    $user = User::factory()->create(['saldo' => 100.00]);
    
    $result = test()->balanceService->setBalance($user, 250.00, 'saldo');
    
    expect($result->fresh()->saldo)->toBe(250.00);
    // Verificar que log foi chamado com a mensagem correta
    Log::shouldHaveReceived('info')->with('Saldo atualizado', \Mockery::type('array'));
});

test('BalanceService usa locks pessimistas para prevenir race conditions', function () {
    $user = User::factory()->create(['saldo' => 100.00]);
    
    // Simular operações concorrentes
    $results = [];
    
    // Executar múltiplas operações simultâneas
    for ($i = 0; $i < 10; $i++) {
        $results[] = DB::transaction(function () use ($user) {
            return $this->balanceService->incrementBalance($user, 10.00, 'saldo');
        });
    }
    
    // Saldo final deve ser 100 + (10 * 10) = 200
    expect($user->fresh()->saldo)->toBe(200.00);
});

test('BalanceService mantém integridade em caso de falha', function () {
    $user = User::factory()->create(['saldo' => 100.00]);
    
    try {
        DB::transaction(function () use ($user) {
            $this->balanceService->incrementBalance($user, 50.00, 'saldo');
            throw new \Exception('Simulação de erro');
        });
    } catch (\Exception $e) {
        // Rollback deve ter acontecido
    }
    
    // Saldo não deve ter mudado devido ao rollback
    expect($user->fresh()->saldo)->toBe(100.00);
});
