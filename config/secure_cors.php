<?php

return [

    /*
    |--------------------------------------------------------------------------
    | URL do frontend (SPA)
    |--------------------------------------------------------------------------
    |
    | Usada como origem CORS em produção quando CORS_ALLOWED_ORIGINS está vazio.
    | Leitura apenas aqui (via env) para compatibilidade com php artisan config:cache;
    | o middleware deve usar config('secure_cors.*'), nunca env() direto.
    |
    */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    /*
    |--------------------------------------------------------------------------
    | Origens extra (produção)
    |--------------------------------------------------------------------------
    |
    | Lista separada por vírgulas no .env, ex.:
    | CORS_ALLOWED_ORIGINS=https://finance.coratri.com,https://www.finance.coratri.com
    |
    */
    'allowed_origins' => array_values(array_unique(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    )))),
];
