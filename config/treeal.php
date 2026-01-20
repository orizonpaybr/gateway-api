<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Treeal/ONZ PIX Configuration
    |--------------------------------------------------------------------------
    |
    | Configurações para integração com Treeal/ONZ PIX.
    | Credenciais sensíveis devem estar no .env, não no banco de dados.
    |
    */

    'environment' => env('TREEAL_ENVIRONMENT', 'sandbox'),

    'qrcodes_api_url' => env('TREEAL_QRCODES_API_URL', 'https://api.pix-h.amplea.coop.br'),
    'accounts_api_url' => env('TREEAL_ACCOUNTS_API_URL', 'https://secureapi.bancodigital.hmg.onz.software/api/v2'),

    'certificate_path' => env('TREEAL_CERTIFICATE_PATH', 'PIX-HMG-CLIENTE.pfx'),
    'certificate_password' => env('TREEAL_CERTIFICATE_PASSWORD'),

    // Credenciais OAuth2 para Accounts API (Cash Out)
    'accounts_client_id' => env('TREEAL_ACCOUNTS_CLIENT_ID'),
    'accounts_client_secret' => env('TREEAL_ACCOUNTS_CLIENT_SECRET'),

    // Credenciais OAuth2 para QR Codes API (Cash In)
    'qrcodes_client_id' => env('TREEAL_QRCODES_CLIENT_ID'),
    'qrcodes_client_secret' => env('TREEAL_QRCODES_CLIENT_SECRET'),

    'pix_key_secondary' => env('TREEAL_PIX_KEY_SECONDARY'),

    'taxa_pix_cash_in' => env('TREEAL_TAXA_PIX_CASH_IN', 0.00),
    'taxa_pix_cash_out' => env('TREEAL_TAXA_PIX_CASH_OUT', 0.00),

    'webhook_secret' => env('TREEAL_WEBHOOK_SECRET'),

    'status' => env('TREEAL_STATUS', false),
];
