<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FYHUB API Contas (corporativa / pagamentos)
    |--------------------------------------------------------------------------
    |
    | Base distinta da API QR Codes. Exige certificado mTLS em todas as
    | requisições (token inclusive).
    |
    */

    'base_url' => env('FYHUB_CONTAS_BASE_URL', 'https://pagamentos.fyhub.com.br/api/v2'),

    'client_id' => env('FYHUB_CONTAS_CLIENT_ID'),

    'client_secret' => env('FYHUB_CONTAS_CLIENT_SECRET'),

    'scope' => env('FYHUB_CONTAS_SCOPE'),

    'timeout' => (int) env('FYHUB_CONTAS_TIMEOUT', 30),

    'token_cache_buffer_seconds' => (int) env('FYHUB_CONTAS_TOKEN_CACHE_BUFFER_SECONDS', 30),

    /*
    |--------------------------------------------------------------------------
    | Certificado mTLS (obrigatório para API Contas)
    |--------------------------------------------------------------------------
    |
    | Opção A: arquivo .pfx (PKCS#12) + senha
    | Opção B: par .crt + .key (PEM). Se ambos estiverem preenchidos, usa-se A ou B
    | conforme fyhub_contas.cert_format.
    |
    */

    'cert_format' => env('FYHUB_CONTAS_CERT_FORMAT', 'pfx'), // pfx | pem

    'cert_pfx_path' => env('FYHUB_CONTAS_CERT_PFX_PATH'),

    'cert_pfx_password' => env('FYHUB_CONTAS_CERT_PFX_PASSWORD'),

    'cert_pem_path' => env('FYHUB_CONTAS_CERT_PEM_PATH'),

    'cert_key_path' => env('FYHUB_CONTAS_CERT_KEY_PATH'),

    'cert_key_password' => env('FYHUB_CONTAS_CERT_KEY_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Segurança TLS
    |--------------------------------------------------------------------------
    */

    'verify_ssl' => filter_var(env('FYHUB_CONTAS_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Pix cash-out (POST /pix/payments/*)
    |--------------------------------------------------------------------------
    */

    'payout_payment_flow' => env('FYHUB_CONTAS_PAYOUT_PAYMENT_FLOW', 'INSTANT'),

    'payout_priority' => env('FYHUB_CONTAS_PAYOUT_PRIORITY', 'NORM'),

    /** HIGH só é permitido pela API quando creditorDocument está definido — ver buildDictPaymentBody */
    'payout_priority_when_document' => env('FYHUB_CONTAS_PAYOUT_PRIORITY_WHEN_DOCUMENT', 'NORM'),

    'payout_expiration_seconds' => (int) env('FYHUB_CONTAS_PAYOUT_EXPIRATION_SECONDS', 600),

    /*
    |--------------------------------------------------------------------------
    | Webhooks API Contas (POST recebidos em /fyhub/contas/webhook)
    |--------------------------------------------------------------------------
    */

    'webhook_rate_limit_per_minute' => (int) env('FYHUB_CONTAS_WEBHOOK_RATE_LIMIT_PER_MINUTE', 18000),

];
