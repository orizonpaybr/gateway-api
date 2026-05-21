<?php

namespace Tests\Unit\Controllers;

use Tests\TestCase;

class TreealWebhookControllerTest extends TestCase
{
    public function test_rejects_request_when_auth_header_configured_and_missing(): void
    {
        config([
            'treeal.webhook_auth_header' => 'X-Treeal-Webhook-Token',
            'treeal.webhook_auth_value' => 'secret-token',
        ]);

        $response = $this->postJson('/treeal/webhook/pix', [
            'pix' => [['txid' => 'abc', 'endToEndId' => 'E123']],
        ]);

        $response->assertStatus(401)
            ->assertJson(['received' => false, 'error' => 'unauthorized']);
    }

    public function test_accepts_payload_without_pix_and_returns_missing_pix_reason(): void
    {
        config([
            'treeal.webhook_auth_header' => '',
            'treeal.webhook_auth_value' => '',
        ]);

        $response = $this->postJson('/treeal/webhook/pix', ['event' => 'ping']);

        $response->assertOk()
            ->assertJson([
                'received' => true,
                'processed' => false,
                'reason' => 'missing_pix',
            ]);
    }
}
