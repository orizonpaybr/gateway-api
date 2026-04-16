<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SIMPAY API - Configurações de Integração
    |--------------------------------------------------------------------------
    |
    | Credenciais e parâmetros para comunicação com a API SIMPAY
    | (PIX, validação de CPF, etc.)
    |
    */

    'base_url' => env('SIMPAY_BASE_URL', 'https://api.somossimpay.com.br/v2/finance'),

    'client_id' => env('SIMPAY_CLIENT_ID'),

    'client_secret' => env('SIMPAY_CLIENT_SECRET'),

    'hmac_key' => env('SIMPAY_HMAC_KEY'),

    'timeout' => (int) env('SIMPAY_TIMEOUT', 30),

    // Margem de segurança (em minutos) antes de expirar o token JWT (token válido por 60min)
    'token_cache_minutes' => (int) env('SIMPAY_TOKEN_CACHE_MINUTES', 55),

    /*
    |--------------------------------------------------------------------------
    | Conta de origem para PIX Cash Out
    |--------------------------------------------------------------------------
    |
    | Agência e número da conta bancária de onde os valores serão debitados
    | nas transferências PIX (Cash Out).
    |
    */

    'source_account_branch' => env('SIMPAY_SOURCE_ACCOUNT_BRANCH', '0001'),

    'source_account_number' => env('SIMPAY_SOURCE_ACCOUNT_NUMBER'),

    /*
    |--------------------------------------------------------------------------
    | Custo fixo por transação PIX (R$)
    |--------------------------------------------------------------------------
    |
    | Valor cobrado pela SIMPAY por cada transação PIX processada.
    | Usado no cálculo de split interno (taxa cliente − custo − afiliado).
    |
    */

    'custo_fixo_transacao' => (float) env('SIMPAY_CUSTO_FIXO_TRANSACAO', 0.035),

    /*
    |--------------------------------------------------------------------------
    | Rate Limit (requisições por minuto para a API SIMPAY)
    |--------------------------------------------------------------------------
    |
    | Capacidade informada: ~300 PIX/segundo = 18.000/min.
    | Webhook rate limit separado para receber notificações da SIMPAY.
    |
    */

    'rate_limit_per_minute' => (int) env('SIMPAY_RATE_LIMIT_PER_MINUTE', 18000),
    'webhook_rate_limit_per_minute' => (int) env('SIMPAY_WEBHOOK_RATE_LIMIT_PER_MINUTE', 18000),

];
