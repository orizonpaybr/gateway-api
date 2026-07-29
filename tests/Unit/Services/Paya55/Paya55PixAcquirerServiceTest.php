<?php

namespace Tests\Unit\Services\Paya55;

use App\Services\FluxPayments\FluxPaymentsPixAcquirerService;
use App\Services\Paya55\Paya55AuthService;
use App\Services\Paya55\Paya55PixAcquirerService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A Paya55 reaproveita a implementação da FluxPayments (mesma API A55). O que
 * pode quebrar é o seam: se o slug do provider vazar de volta para
 * "fluxpayments", a Paya55 passa a chamar o host errado com a credencial errada
 * e a gravar executor_ordem errado — daí o custo/estorno/webhook irem para a
 * adquirente errada. É isso que este teste tranca.
 */
class Paya55PixAcquirerServiceTest extends TestCase
{
    private function service(): Paya55PixAcquirerService
    {
        config([
            'paya55.base_url' => 'https://api.paya55.com',
            'paya55.api_key' => 'live_paya',
            'paya55.public_key' => 'pub_paya',
            'paya55.webhook_url' => 'https://coratri.test/paya55/webhook',
            'paya55.expires_in_seconds' => null,
            'paya55.expires_in_days' => 1,
            // Credenciais da FluxPayments propositalmente diferentes: se o seam
            // vazar, os asserts de host/auth abaixo pegam.
            'fluxpayments.base_url' => 'https://api.fluxpaymentss.com',
            'fluxpayments.api_key' => 'live_flux',
            'fluxpayments.public_key' => 'pub_flux',
        ]);

        return new Paya55PixAcquirerService(new Paya55AuthService);
    }

    public function test_reference_e_family_separam_paya55_da_fluxpayments(): void
    {
        $this->assertSame('paya55', $this->service()->getReference());
        $this->assertSame('fluxpayments', app(FluxPaymentsPixAcquirerService::class)->getReference());
        $this->assertContains('paya55', FluxPaymentsPixAcquirerService::FAMILY);
        $this->assertContains('fluxpayments', FluxPaymentsPixAcquirerService::FAMILY);
    }

    public function test_cash_in_usa_host_e_credenciais_da_paya55(): void
    {
        Http::fake([
            'api.paya55.com/*' => Http::response([
                'id' => 'txn-paya-1',
                'status' => 'pending',
                'pix' => ['qrcode' => '000201...br.gov.bcb.pix', 'expiresAt' => '2026-07-30T10:00:00.000Z'],
            ], 201),
        ]);

        $result = $this->service()->createCharge(100.00, [
            'name' => 'João Silva',
            'document' => '01234567890',
            'email' => 'joao@email.com',
            'phone' => '11999998888',
        ], 'order-123', 'Depósito');

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame('txn-paya-1', $result['correlationID']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://api.paya55.com/api/v1/transactions'
                && $request->header('Authorization')[0] === 'Basic '.base64_encode('live_paya:pub_paya')
                && $body['amount'] === 10000                       // centavos
                && $body['postbackUrl'] === 'https://coratri.test/paya55/webhook'
                && $body['externalRef'] === 'order-123'
                && $body['pix'] === ['expiresInDays' => 1];
        });
    }

    public function test_pix_out_envia_idempotency_key_e_chave_formatada(): void
    {
        Http::fake([
            'api.paya55.com/*' => Http::response([
                'success' => true,
                'data' => ['id' => 'out-1', 'status' => 'PROCESSING'],
            ], 200),
        ]);

        $result = $this->service()->createPayout(
            50.00,
            '012.345.678-90',
            'cpf',
            'Saque',
            'withdrawal-456',
            'João Silva',
            '01234567890'
        );

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame('PROCESSING', $result['status']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://api.paya55.com/api/v1/transactions/pix-out'
                && $request->header('Idempotency-Key')[0] === 'withdrawal-456'
                && $body['amount'] === 5000
                && $body['pixKey'] === '01234567890'   // só dígitos
                && $body['pixKeyType'] === 'CPF';
        });
    }

    public function test_mensagem_de_erro_cita_paya55_e_nao_fluxpayments(): void
    {
        Http::fake([
            'api.paya55.com/*' => Http::response(['message' => null], 500),
        ]);

        $result = $this->service()->createCharge(100.00, [
            'name' => 'João Silva',
            'document' => '01234567890',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Paya55', $result['message']);
        $this->assertStringNotContainsString('FluxPayments', $result['message']);
    }
}
