<?php

namespace App\Http\Controllers\Api;

use App\Services\FluxPayments\FluxPaymentsPixAcquirerService;
use App\Services\Paya55\Paya55PixAcquirerService;

/**
 * Webhooks Paya55 (POST /paya55/webhook).
 *
 * Mesmo envelope e mesmos eventos da FluxPayments — só muda o slug do provider
 * usado para achar o depósito/saque (executor_ordem) e o webhook_secret.
 */
class Paya55WebhookController extends FluxPaymentsWebhookController
{
    protected function provider(): string
    {
        return 'paya55';
    }

    protected function acquirer(): FluxPaymentsPixAcquirerService
    {
        return app(Paya55PixAcquirerService::class);
    }
}
