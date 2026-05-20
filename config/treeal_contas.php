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

    'base_url' => env('TREEAL_CONTAS_BASE_URL'),

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

];
