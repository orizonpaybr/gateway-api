<?php

namespace Tests\Unit\Controllers;

use Tests\TestCase;

class TreealContasWebhookControllerTest extends TestCase
{
    public function test_rejects_request_when_auth_header_configured_and_missing(): void
    {
        config([
            'treeal_contas.webhook_auth_header' => 'X-Treeal-Contas-Webhook-Token',
            'treeal_contas.webhook_auth_value' => 'secret-token',
        ]);

        $response = $this->postJson('/treeal/contas/webhook', [
            'type' => 'TRANSFER',
            'data' => ['status' => 'LIQUIDATED', 'endToEndId' => 'E123'],
        ]);

        $response->assertStatus(401)
            ->assertJson(['received' => false, 'error' => 'unauthorized']);
    }

    public function test_ignores_infraction_type(): void
    {
        config([
            'treeal_contas.webhook_auth_header' => '',
            'treeal_contas.webhook_auth_value' => '',
        ]);

        $response = $this->postJson('/treeal/contas/webhook', [
            'type' => 'INFRACTION',
            'data' => ['id' => 'uuid', 'status' => 'OPEN'],
        ]);

        $response->assertOk()
            ->assertJson(['received' => true, 'processed' => false, 'reason' => 'type_not_handled']);
    }

    public function test_rejects_invalid_payload(): void
    {
        $response = $this->postJson('/treeal/contas/webhook', ['type' => 'TRANSFER']);

        $response->assertOk()
            ->assertJson(['received' => true, 'processed' => false, 'reason' => 'invalid_payload']);
    }
}
