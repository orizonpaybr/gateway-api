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

    public function test_format_pix_key_for_dict_normalizes_phone_email_and_evp(): void
    {
        $service = new TreealContasPixOutService($this->createMock(TreealContasApiClient::class));

        $this->assertSame('+5584999518869', $service->formatPixKeyForDict('84999518869', 'telefone'));
        $this->assertSame('+5584999518869', $service->formatPixKeyForDict('(84) 99951-8869', 'phone'));
        $this->assertSame('cliente@email.com', $service->formatPixKeyForDict('Cliente@Email.com', 'email'));
        $this->assertSame(
            'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            $service->formatPixKeyForDict('A1B2C3D4-E5F6-7890-ABCD-EF1234567890', 'aleatoria')
        );
        $this->assertSame('12345678909', $service->formatPixKeyForDict('123.456.789-09', 'cpf'));
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
