<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Paya55 (A55) API — Configurações de Integração
    |--------------------------------------------------------------------------
    |
    | Mesma família de API da FluxPayments (endpoints /api/v1/transactions,
    | Basic Auth apiKey:publicKey, envelope de webhook id/type/event/objectId).
    | A implementação é compartilhada em App\Services\FluxPayments\*, que lê as
    | chaves deste arquivo quando o provider resolvido é "paya55".
    |
    | Docs: https://app.paya55.com/docs
    |
    */

    'base_url' => env('PAYA55_BASE_URL', 'https://api.paya55.com'),

    'api_key' => env('PAYA55_API_KEY'),

    'public_key' => env('PAYA55_PUBLIC_KEY'),

    'timeout' => (int) env('PAYA55_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | User-Agent obrigatório (formato: Aplicacao/Versao (+contato))
    |--------------------------------------------------------------------------
    */

    'user_agent' => env(
        'PAYA55_USER_AGENT',
        'CoratriGateway/1.0 (+contato@coratri.com.br)'
    ),

    /*
    |--------------------------------------------------------------------------
    | Expiração do QR Code PIX
    |--------------------------------------------------------------------------
    |
    | Informe expires_in_seconds OU expires_in_days (não ambos no payload).
    | Se ambos estiverem configurados, seconds tem prioridade na API.
    |
    */

    'expires_in_seconds' => env('PAYA55_EXPIRES_IN_SECONDS') !== null
        ? (int) env('PAYA55_EXPIRES_IN_SECONDS')
        : null,

    'expires_in_days' => (int) env('PAYA55_EXPIRES_IN_DAYS', 1),

    /*
    |--------------------------------------------------------------------------
    | Webhook (postbackUrl enviado na criação da cobrança)
    |--------------------------------------------------------------------------
    */

    'webhook_url' => env('PAYA55_WEBHOOK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Secret opcional para validar x-webhook-signature (HMAC-SHA256)
    |--------------------------------------------------------------------------
    */

    'webhook_secret' => env('PAYA55_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Custo por transação PIX: R$ 0,03
    |--------------------------------------------------------------------------
    */

    'custo_fixo_transacao' => (float) env('PAYA55_CUSTO_FIXO_TRANSACAO', 0.03),

    /*
    |--------------------------------------------------------------------------
    | Limite de throughput Paya55: 300 transações PIX por segundo
    |--------------------------------------------------------------------------
    */

    'rate_limit_per_second' => (int) env('PAYA55_RATE_LIMIT_PER_SECOND', 300),

    'rate_limit_per_minute' => (int) env('PAYA55_RATE_LIMIT_PER_MINUTE', 18000),

    'webhook_rate_limit_per_minute' => (int) env('PAYA55_WEBHOOK_RATE_LIMIT_PER_MINUTE', 18000),

    /*
    |--------------------------------------------------------------------------
    | Fallbacks de customer quando o merchant não envia e-mail/telefone
    |--------------------------------------------------------------------------
    */

    'fallback_email' => env('PAYA55_FALLBACK_EMAIL', 'noreply@coratri.com.br'),

    'fallback_phone' => env('PAYA55_FALLBACK_PHONE', '11999999999'),

];
