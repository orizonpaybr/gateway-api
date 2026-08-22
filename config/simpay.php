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

    // Raiz da API (sem /v2/finance) — usada pelos endpoints v3 (MED: /v3/meds/...).
    'base_url_root' => env('SIMPAY_BASE_URL_ROOT', 'https://api.somossimpay.com.br'),

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

    'custo_fixo_transacao' => (float) env('SIMPAY_CUSTO_FIXO_TRANSACAO', 0.75),

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
    'rate_limit_per_second' => (int) env('SIMPAY_RATE_LIMIT_PER_SECOND', 500),
    'webhook_rate_limit_per_minute' => (int) env('SIMPAY_WEBHOOK_RATE_LIMIT_PER_MINUTE', 18000),

    /*
    |--------------------------------------------------------------------------
    | Autenticação do webhook inbound (opt-in)
    |--------------------------------------------------------------------------
    |
    | A SIMPAY reenvia, no header de cada webhook, o `authorization_token` que
    | for definido no cadastro do webhook (POST /v3/accounts/api/webhooks).
    | Defina o mesmo valor aqui e no cadastro para exigir a validação.
    | Vazio = sem checagem (não recomendado em produção com cash-out).
    |
    */

    'webhook_authorization_token' => env('SIMPAY_WEBHOOK_AUTHORIZATION_TOKEN'),

    'webhook_authorization_header' => env('SIMPAY_WEBHOOK_AUTHORIZATION_HEADER', 'Authorization'),

];
