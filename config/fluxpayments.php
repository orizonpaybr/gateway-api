<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FluxPayments API — Configurações de Integração (PIX IN)
    |--------------------------------------------------------------------------
    |
    | Autenticação: Basic Auth com apiKey (usuário) e publicKey (senha).
    | Em produção a apiKey deve ter prefixo live_.
    | User-Agent obrigatório em todas as requisições.
    |
    */

    'base_url' => env('FLUXPAYMENTS_BASE_URL', 'https://api.fluxpaymentss.com'),

    'api_key' => env('FLUXPAYMENTS_API_KEY'),

    'public_key' => env('FLUXPAYMENTS_PUBLIC_KEY'),

    'timeout' => (int) env('FLUXPAYMENTS_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | User-Agent obrigatório (formato: Aplicacao/Versao (+contato))
    |--------------------------------------------------------------------------
    */

    'user_agent' => env(
        'FLUXPAYMENTS_USER_AGENT',
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

    'expires_in_seconds' => env('FLUXPAYMENTS_EXPIRES_IN_SECONDS') !== null
        ? (int) env('FLUXPAYMENTS_EXPIRES_IN_SECONDS')
        : null,

    'expires_in_days' => (int) env('FLUXPAYMENTS_EXPIRES_IN_DAYS', 1),

    /*
    |--------------------------------------------------------------------------
    | Webhook (postbackUrl enviado na criação da cobrança)
    |--------------------------------------------------------------------------
    */

    'webhook_url' => env('FLUXPAYMENTS_WEBHOOK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Secret opcional para validar x-webhook-signature (HMAC-SHA256)
    |--------------------------------------------------------------------------
    |
    | Se definido, também é enviado como webhookSecret na criação de
    | cobranças/transferências (postbackUrl).
    |
    */

    'webhook_secret' => env('FLUXPAYMENTS_WEBHOOK_SECRET'),

    'custo_fixo_transacao' => (float) env('FLUXPAYMENTS_CUSTO_FIXO_TRANSACAO', 0.09),

    /*
    |--------------------------------------------------------------------------
    | Limite de throughput FluxPayments (transações PIX por segundo)
    |--------------------------------------------------------------------------
    */

    'rate_limit_per_second' => (int) env('FLUXPAYMENTS_RATE_LIMIT_PER_SECOND', 300),

    'rate_limit_per_minute' => (int) env('FLUXPAYMENTS_RATE_LIMIT_PER_MINUTE', 18000),

    'webhook_rate_limit_per_minute' => (int) env('FLUXPAYMENTS_WEBHOOK_RATE_LIMIT_PER_MINUTE', 18000),

    /*
    |--------------------------------------------------------------------------
    | Fallbacks de customer quando o merchant não envia e-mail/telefone
    |--------------------------------------------------------------------------
    */

    'fallback_email' => env('FLUXPAYMENTS_FALLBACK_EMAIL', 'noreply@coratri.com.br'),

    'fallback_phone' => env('FLUXPAYMENTS_FALLBACK_PHONE', '11999999999'),

];
