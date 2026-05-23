<?php

return [

    /*
    |--------------------------------------------------------------------------
    | TREEAL API Contas (CashOut)
    |--------------------------------------------------------------------------
    |
    | Base distinta da API QR Codes. Exige certificado mTLS e credenciais
    | OAuth próprias (token independente do CashIn).
    |
    */

    'base_url' => env('TREEAL_CONTAS_BASE_URL', 'https://secureapi.treealhmg-hmg.onz.software'),

    'client_id' => env('TREEAL_CONTAS_CLIENT_ID'),

    'client_secret' => env('TREEAL_CONTAS_CLIENT_SECRET'),

    'scope' => env('TREEAL_CONTAS_SCOPE'),

    'timeout' => (int) env('TREEAL_CONTAS_TIMEOUT', 30),

    'token_cache_buffer_seconds' => (int) env('TREEAL_CONTAS_TOKEN_CACHE_BUFFER_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Certificado mTLS (obrigatório — Accounts API)
    |--------------------------------------------------------------------------
    */

    'cert_format' => env('TREEAL_CONTAS_CERT_FORMAT', 'pfx'),

    'cert_pfx_path' => env('TREEAL_CONTAS_CERT_PFX_PATH'),

    'cert_pfx_password' => env('TREEAL_CONTAS_CERT_PFX_PASSWORD'),

    'cert_pem_path' => env('TREEAL_CONTAS_CERT_PEM_PATH'),

    'cert_key_path' => env('TREEAL_CONTAS_CERT_KEY_PATH'),

    'cert_key_password' => env('TREEAL_CONTAS_CERT_KEY_PASSWORD'),

    'verify_ssl' => filter_var(env('TREEAL_CONTAS_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),

    'webhook_rate_limit_per_minute' => (int) env('TREEAL_CONTAS_WEBHOOK_RATE_LIMIT_PER_MINUTE', 18000),

    /*
    |--------------------------------------------------------------------------
    | Pix cash-out (POST /pix/payments/*) — escopos OAuth: pix.read, pix.write
    |--------------------------------------------------------------------------
    */

    'payout_payment_flow' => env('TREEAL_CONTAS_PAYOUT_PAYMENT_FLOW', 'INSTANT'),

    'payout_priority' => env('TREEAL_CONTAS_PAYOUT_PRIORITY', 'NORM'),

    'payout_priority_when_document' => env('TREEAL_CONTAS_PAYOUT_PRIORITY_WHEN_DOCUMENT', 'NORM'),

    'payout_expiration_seconds' => (int) env('TREEAL_CONTAS_PAYOUT_EXPIRATION_SECONDS', 600),

    /*
    |--------------------------------------------------------------------------
    | Webhooks Contas (POST {uri} com {type,data})
    |--------------------------------------------------------------------------
    */

    'webhook_base_url' => env('TREEAL_CONTAS_WEBHOOK_BASE_URL'),

    'webhook_error_email' => env('TREEAL_CONTAS_WEBHOOK_ERROR_EMAIL'),

    'webhook_auth_header' => env('TREEAL_CONTAS_WEBHOOK_AUTH_HEADER'),

    'webhook_auth_value' => env('TREEAL_CONTAS_WEBHOOK_AUTH_VALUE'),

];
