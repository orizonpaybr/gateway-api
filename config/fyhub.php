<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FYHUB API - Configurações de Integração
    |--------------------------------------------------------------------------
    |
    | Credenciais e parâmetros para comunicação com a API FYHUB
    | (OAuth2 e operações PIX).
    |
    */

    'base_url' => env('FYHUB_BASE_URL', 'https://api.qrcode.fyhub.com.br'),

    'client_id' => env('FYHUB_CLIENT_ID'),

    'client_secret' => env('FYHUB_CLIENT_SECRET'),

    'scope' => env('FYHUB_SCOPE'),

    'timeout' => (int) env('FYHUB_TIMEOUT', 30),

    'pix_key' => env('FYHUB_PIX_KEY'),

    'charge_expiration_seconds' => (int) env('FYHUB_CHARGE_EXPIRATION_SECONDS', 3600),

    // 0 = valor fixo; 1 = pagador pode alterar valor final.
    'allow_amount_change' => (bool) env('FYHUB_ALLOW_AMOUNT_CHANGE', false),

    // Natureza padrão para devolução de Pix recebidos.
    'refund_nature' => env('FYHUB_REFUND_NATURE', 'ORIGINAL'),

    // Quando true, cria um /loc antes do /cob/{txid} e vincula via loc.id.
    'use_managed_locations' => (bool) env('FYHUB_USE_MANAGED_LOCATIONS', true),

    'webhook_rate_limit_per_minute' => (int) env('FYHUB_WEBHOOK_RATE_LIMIT_PER_MINUTE', 18000),

    /*
    |--------------------------------------------------------------------------
    | Margem de segurança do token (segundos)
    |--------------------------------------------------------------------------
    |
    | O TTL de cache é calculado com base no expires_in retornado pela API
    | menos esta margem para reduzir risco de token expirar no meio da requisição.
    |
    */
    'token_cache_buffer_seconds' => (int) env('FYHUB_TOKEN_CACHE_BUFFER_SECONDS', 30),
];
