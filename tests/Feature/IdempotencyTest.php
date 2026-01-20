<?php

use App\Models\Solicitacoes;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

test('Webhook com mesma idempotency key não é processado duas vezes', function () {
    $webhookService = app(WebhookService::class);
    
    $request = Request::create('/webhook', 'POST', [
        'type' => 'order.paid',
        'data' => ['id' => 'test-transaction-idempotency']
    ]);
    
    $request->headers->set('Idempotency-Key', 'unique-key-123');
    
    $processedCount = 0;
    
    $processor = function () use (&$processedCount) {
        $processedCount++;
        return response()->json(['status' => 'success']);
    };
    
    // Primeira chamada
    $response1 = $webhookService->processWebhook($request, 'pagarme', $processor);
    
    // Segunda chamada com mesma key
    $response2 = $webhookService->processWebhook($request, 'pagarme', $processor);
    
    // Terceira chamada com mesma key
    $response3 = $webhookService->processWebhook($request, 'pagarme', $processor);
    
    // Processor deve ter sido chamado apenas uma vez
    expect($processedCount)->toBe(1);
    
    // Apenas 1 log com status PROCESSED
    expect(WebhookLog::where('status', 'PROCESSED')->count())->toBe(1);
    
    // Respostas devem indicar que já foi processado
    expect($response2->getStatusCode())->toBe(200);
    expect($response3->getStatusCode())->toBe(200);
});

test('Webhook gera idempotency key baseada no payload se não fornecida', function () {
    $webhookService = app(WebhookService::class);
    
    $payload = [
        'type' => 'order.paid',
        'data' => ['id' => 'test-transaction-auto-key']
    ];
    
    $request = Request::create('/webhook', 'POST', $payload);
    
    // Primeira chamada
    $webhookService->processWebhook($request, 'pagarme', function () {
        return response()->json(['status' => 'success']);
    });
    
    $log1 = WebhookLog::first();
    $idempotencyKey1 = $log1->idempotency_key;
    
    // Segunda chamada com mesmo payload (sem header)
    $request2 = Request::create('/webhook', 'POST', $payload);
    $webhookService->processWebhook($request2, 'pagarme', function () {
        return response()->json(['status' => 'success']);
    });
    
    // Deve ter gerado mesma key e não processado novamente
    $logs = WebhookLog::all();
    expect($logs->count())->toBe(1); // Apenas 1 log
    expect($logs->first()->idempotency_key)->toBe($idempotencyKey1);
});

test('PaymentProcessingService não processa pagamento já processado', function () {
    $user = User::factory()->create(['saldo' => 1000.00]);
    
    $cashin = Solicitacoes::factory()->create([
        'user_id' => $user->username,
        'status' => 'PAID_OUT', // Já processado
        'amount' => 100.00,
        'deposito_liquido' => 95.00,
    ]);
    
    $balanceBefore = $user->saldo;
    $paymentService = app(\App\Services\PaymentProcessingService::class);
    
    // Tentar processar novamente não deve lançar exceção
    $paymentService->processPaymentReceived($cashin);
    
    // Saldo não deve ter mudado
    expect($user->fresh()->saldo)->toBe($balanceBefore);
    
    // Não deve criar novo evento
    expect(\App\Models\PaymentEvent::where('transaction_id', $cashin->id)->count())->toBe(0);
});

test('WebhookService trata requisições simultâneas duplicadas', function () {
    $webhookService = app(WebhookService::class);
    
    $request = Request::create('/webhook', 'POST', [
        'type' => 'order.paid',
        'data' => ['id' => 'test-simultaneous']
    ]);
    
    $request->headers->set('Idempotency-Key', 'simultaneous-key');
    
    $processedCount = 0;
    
    $processor = function () use (&$processedCount) {
        $processedCount++;
        // Simular processamento lento
        usleep(100000); // 0.1 segundos
        return response()->json(['status' => 'success']);
    };
    
    // Simular duas requisições simultâneas (em sequência devido ao teste)
    $response1 = $webhookService->processWebhook($request, 'pagarme', $processor);
    
    // Segunda requisição deve detectar que está PROCESSING e aguardar
    $response2 = $webhookService->processWebhook($request, 'pagarme', $processor);
    
    // Processor deve ter sido chamado apenas uma vez
    expect($processedCount)->toBe(1);
});
