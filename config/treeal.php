<?php

return [

    /*
    |--------------------------------------------------------------------------
    | TREEAL API QR Codes (CashIn)
    |--------------------------------------------------------------------------
    |
    | Credenciais e parâmetros para comunicação com a API Treeal de geração
    | de QR Code / cobrança PIX (OAuth2 + mTLS).
    |
    */

    'base_url' => env('TREEAL_BASE_URL', 'https://api.pix-h.treeal.com'),

    'client_id' => env('TREEAL_CLIENT_ID'),

    'client_secret' => env('TREEAL_CLIENT_SECRET'),

    'scope' => env('TREEAL_SCOPE'),

    'timeout' => (int) env('TREEAL_TIMEOUT', 30),

    'pix_key' => env('TREEAL_PIX_KEY'),

    'charge_expiration_seconds' => (int) env('TREEAL_CHARGE_EXPIRATION_SECONDS', 3600),

    'allow_amount_change' => (bool) env('TREEAL_ALLOW_AMOUNT_CHANGE', false),

    'refund_nature' => env('TREEAL_REFUND_NATURE', 'ORIGINAL'),

    'use_managed_locations' => (bool) env('TREEAL_USE_MANAGED_LOCATIONS', false),

    'webhook_rate_limit_per_minute' => (int) env('TREEAL_WEBHOOK_RATE_LIMIT_PER_MINUTE', 18000),

    /*
    |--------------------------------------------------------------------------
    | Webhook CashIn (POST {webhookUrl}/pix)
    |--------------------------------------------------------------------------
    |
    | webhook_base_url: URL registrada na Treeal via PUT /webhook/{chave}.
    | Default: {APP_URL}/treeal/webhook → callback em /treeal/webhook/pix
    |
    | webhook_auth_header / webhook_auth_value: header customizado recomendado
    | pela Treeal para identificar webhooks legítimos.
    |
    */

    'webhook_base_url' => env('TREEAL_WEBHOOK_BASE_URL'),

    'webhook_auth_header' => env('TREEAL_WEBHOOK_AUTH_HEADER'),

    'webhook_auth_value' => env('TREEAL_WEBHOOK_AUTH_VALUE'),

    /*
    |--------------------------------------------------------------------------
    | Custo fixo por transação PIX (R$)
    |--------------------------------------------------------------------------
    |
    | Custo da Treeal por transação (cash-in e cash-out). Usado no split interno
    | (TaxaSaqueHelper / TaxaFlexível) e nas métricas de lucro.
    |
    */

    'custo_fixo_transacao' => (float) env('TREEAL_CUSTO_FIXO_TRANSACAO', 0.05),

    /*
    |--------------------------------------------------------------------------
    | Limite de throughput informado pela TREEAL (transações por segundo)
    |--------------------------------------------------------------------------
    |
    | Aplicado nas rotas PIX quando a adquirente padrão do usuário é treeal.
    |
    */

    'rate_limit_per_second' => (int) env('TREEAL_RATE_LIMIT_PER_SECOND', 300),

    /*
    |--------------------------------------------------------------------------
    | Margem de segurança do token (segundos)
    |--------------------------------------------------------------------------
    |
    | O TTL de cache é calculado com base no expires_in retornado pela API
    | (máx. 300s) menos esta margem.
    |
    */

    'token_cache_buffer_seconds' => (int) env('TREEAL_TOKEN_CACHE_BUFFER_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Certificado mTLS (obrigatório — QrCode Generation API)
    |--------------------------------------------------------------------------
    |
    | Suporta arquivo .pfx + senha OU par .crt/.pem + .key
    | (conforme TREEAL_CERT_FORMAT = pfx | pem).
    |
    */

    'cert_format' => env('TREEAL_CERT_FORMAT', 'pfx'),
    'cert_pfx_path' => env('TREEAL_CERT_PFX_PATH'),
    'cert_pfx_password' => env('TREEAL_CERT_PFX_PASSWORD'),
    'cert_pem_path' => env('TREEAL_CERT_PEM_PATH'),
    'cert_key_path' => env('TREEAL_CERT_KEY_PATH'),
    'cert_key_password' => env('TREEAL_CERT_KEY_PASSWORD'),
    'verify_ssl' => env('TREEAL_VERIFY_SSL', true),

];
