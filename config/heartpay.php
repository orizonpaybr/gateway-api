<?php

return [
    /*
    |--------------------------------------------------------------------------
    | HeartPay — Banking as a Service (PIX)
    |--------------------------------------------------------------------------
    |
    | Integração com a plataforma HeartPay para processamento de PIX.
    | Autenticação via API Key (Bearer token) no header Authorization.
    | Todos os valores monetários são em CENTAVOS (inteiros).
    |
    | Base URL: https://app.heartpag.com/api/v1/client
    |
    */

    'environment' => env('HEARTPAY_ENVIRONMENT', 'production'),

    'api_url' => env('HEARTPAY_API_URL', 'https://app.heartpag.com/api/v1/client'),

    /*
    |--------------------------------------------------------------------------
    | Autenticação — API Key
    |--------------------------------------------------------------------------
    |
    | Formato: hpay_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX (prefixo hpay_ + 32 chars)
    | Enviada como: Authorization: Bearer {api_key}
    |
    | Obter em: Painel HeartPay → Configurações → Chaves de API
    |
    */
    'api_key' => env('HEARTPAY_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Custo Fixo por Transação
    |--------------------------------------------------------------------------
    |
    | Custo fixo cobrado pela HeartPay por cada transação PIX (em reais).
    | Descontado do lucro líquido da aplicação.
    |
    */
    'custo_fixo_por_transacao' => env('HEARTPAY_CUSTO_FIXO_POR_TRANSACAO', 0.025),

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    |
    | URL do webhook: https://api.orizonpay.com/heartpay/webhook
    | Método: POST
    |
    | Segurança: HMAC-SHA256 com api_key para validação de assinatura.
    |
    */
    'webhook_secret' => env('HEARTPAY_WEBHOOK_SECRET'),

    'webhook_ips' => array_filter(explode(',', env('HEARTPAY_WEBHOOK_IPS', ''))),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | HeartPay permite 10.000 requisições por 15 minutos por API Key.
    | Headers de resposta: RateLimit-Limit, RateLimit-Remaining, RateLimit-Reset
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Retry & Timeout
    |--------------------------------------------------------------------------
    */
    'max_retries' => env('HEARTPAY_MAX_RETRIES', 3),
    'retry_delay_ms' => env('HEARTPAY_RETRY_DELAY_MS', 1000),
    'timeout' => env('HEARTPAY_TIMEOUT', 30),
    'connect_timeout' => env('HEARTPAY_CONNECT_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | SSL
    |--------------------------------------------------------------------------
    */
    'verify_ssl' => env('HEARTPAY_VERIFY_SSL', true),

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    |
    | Se a integração está ativa.
    |
    */
    'status' => env('HEARTPAY_STATUS', false),

    /*
    |--------------------------------------------------------------------------
    | Validação estrita de valor no webhook
    |--------------------------------------------------------------------------
    */
    'strict_amount_validation' => env('HEARTPAY_STRICT_AMOUNT_VALIDATION', false),
];
