<?php

namespace App\Services\Paya55;

use App\Services\FluxPayments\FluxPaymentsPixAcquirerService;

/**
 * Adquirente PIX Paya55 (A55).
 *
 * A API é idêntica à da FluxPayments (POST /api/v1/transactions,
 * GET /api/v1/transactions/pix-in|pix-out, POST /api/v1/transactions/pix-out,
 * DELETE /api/v1/transactions/{id}/refund, GET /api/v1/balance, mesmo envelope
 * de webhook), então toda a implementação é herdada — aqui só trocam o slug do
 * provider (config/paya55.php, executor_ordem, rota /paya55/webhook) e o rótulo.
 *
 * Custo por transação: R$ 0,03 — throughput: 300 TPS (config/paya55.php).
 */
class Paya55PixAcquirerService extends FluxPaymentsPixAcquirerService
{
    protected string $provider = 'paya55';

    protected string $label = 'Paya55';

    public function __construct(Paya55AuthService $auth)
    {
        parent::__construct($auth);
    }
}
