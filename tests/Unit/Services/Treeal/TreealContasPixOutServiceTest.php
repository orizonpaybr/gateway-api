<?php

namespace Tests\Unit\Services\Treeal;

use App\Services\TreealContas\TreealContasApiClient;
use App\Services\TreealContas\TreealContasPixOutService;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

class TreealContasPixOutServiceTest extends TestCase
{
    public function test_build_dict_payment_body_uses_treeal_contas_config(): void
    {
        config([
            'treeal_contas.payout_priority' => 'NORM',
            'treeal_contas.payout_payment_flow' => 'INSTANT',
            'treeal_contas.payout_expiration_seconds' => 600,
        ]);

        $service = new TreealContasPixOutService($this->createMock(TreealContasApiClient::class));
        $body = $service->buildDictPaymentBody('+5511999999999', 50.25, 'Saque', '12345678909');

        $this->assertSame('+5511999999999', $body['pixKey']);
        $this->assertSame('NORM', $body['priority']);
        $this->assertSame('INSTANT', $body['paymentFlow']);
        $this->assertSame(600, $body['expiration']);
        $this->assertSame(50.25, $body['payment']['amount']);
        $this->assertSame('12345678909', $body['creditorDocument']);
    }

    public function test_initiate_payment_by_dict_sends_idempotency_header(): void
    {
        $client = $this->createMock(TreealContasApiClient::class);
        $client->expects($this->once())
            ->method('postJson')
            ->with(
                '/pix/payments/dict',
                ['pixKey' => 'a@b.com'],
                ['x-idempotency-key' => 'key-123']
            )
            ->willReturn(new Response(new \GuzzleHttp\Psr7\Response(202, [], json_encode([
                'endToEndId' => 'E123',
                'status' => 'PROCESSING',
            ]))));

        $result = (new TreealContasPixOutService($client))->initiatePaymentByDict('key-123', ['pixKey' => 'a@b.com']);

        $this->assertTrue($result['success']);
        $this->assertSame('E123', $result['data']['endToEndId']);
    }
}
