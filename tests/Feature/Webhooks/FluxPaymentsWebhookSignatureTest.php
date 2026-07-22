<?php

namespace Tests\Feature\Webhooks;

use App\Models\Adquirente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cobre o bug real: cada nominal FluxPayments assina webhooks com o próprio
 * webhook_secret, mas a validação só conferia o secret global do .env.
 * Um webhook legítimo de uma nominal (credentials no banco) precisa passar,
 * e um assinado com secret desconhecido continua sendo rejeitado.
 */
class FluxPaymentsWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fluxpayments.webhook_secret' => 'global-secret-do-env']);
    }

    private function postSignedWebhook(string $body, string $secret)
    {
        $signature = hash_hmac('sha256', $body, $secret);

        return $this->call(
            'POST',
            '/fluxpayments/webhook',
            [],
            [],
            [],
            [
                'HTTP_x-webhook-signature' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $body
        );
    }

    public function test_webhook_assinado_com_secret_da_nominal_passa_da_validacao(): void
    {
        Adquirente::create([
            'adquirente' => 'FluxPayments (Vendas Digitais)',
            'status' => true,
            'url' => 'https://api.fluxpaymentss.com',
            'referencia' => 'fluxpayments-vendas-digitais',
            'provider' => 'fluxpayments',
            'credentials' => [
                'api_key' => 'live_x',
                'public_key' => 'pub_x',
                'webhook_secret' => 'secret-da-nominal',
            ],
            'is_default' => false,
        ]);

        $response = $this->postSignedWebhook('{}', 'secret-da-nominal');

        // Passou da validação de assinatura (chegou no "missing_event", não no 401 de assinatura inválida)
        $response->assertStatus(200);
        $response->assertJson(['received' => true, 'processed' => false, 'reason' => 'missing_event']);
    }

    public function test_webhook_assinado_com_secret_desconhecido_continua_rejeitado(): void
    {
        Adquirente::create([
            'adquirente' => 'FluxPayments (Vendas Digitais)',
            'status' => true,
            'url' => 'https://api.fluxpaymentss.com',
            'referencia' => 'fluxpayments-vendas-digitais',
            'provider' => 'fluxpayments',
            'credentials' => [
                'api_key' => 'live_x',
                'public_key' => 'pub_x',
                'webhook_secret' => 'secret-da-nominal',
            ],
            'is_default' => false,
        ]);

        $response = $this->postSignedWebhook('{}', 'secret-totalmente-errado');

        $response->assertStatus(401);
        $response->assertJson(['received' => false, 'processed' => false, 'reason' => 'invalid_signature']);
    }

    public function test_webhook_assinado_com_secret_global_legado_continua_funcionando(): void
    {
        $response = $this->postSignedWebhook('{}', 'global-secret-do-env');

        $response->assertStatus(200);
        $response->assertJson(['received' => true, 'processed' => false, 'reason' => 'missing_event']);
    }
}
