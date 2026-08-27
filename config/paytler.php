<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PAYTLER API - Configurações de Integração
    |--------------------------------------------------------------------------
    |
    | Adquirente PIX Paytler. Auth OAuth2 (client_id/client_secret -> Bearer JWT),
    | SEM HMAC. Todos os endpoints ficam sob /v1/customers.
    | Doc: https://api.paytler.com/v1/docs
    |
    */

    // Inclui o prefixo /v1/customers (todos os endpoints partem daqui).
    'base_url' => env('PAYTLER_BASE_URL', 'https://api.paytler.com/v1/customers'),

    'client_id' => env('PAYTLER_CLIENT_ID'),

    'client_secret' => env('PAYTLER_CLIENT_SECRET'),

    'timeout' => (int) env('PAYTLER_TIMEOUT', 30),

    // Margem de cache do Bearer (o token vem com expires_in; usamos o menor entre
    // expires_in e este limite).
    'token_cache_minutes' => (int) env('PAYTLER_TOKEN_CACHE_MINUTES', 55),

    /*
    |--------------------------------------------------------------------------
    | Custo por transação PIX
    |--------------------------------------------------------------------------
    | Paytler cobra por PERCENTUAL (2% do valor), não custo fixo em R$.
    | custo_percentual_transacao = 2 significa 2%. Mantém custo_fixo (=0) só para
    | o helper de custo combinar fixo + percentual de forma uniforme entre adquirentes.
    | AJUSTAR via .env se o contrato mudar.
    */
    'custo_fixo_transacao' => (float) env('PAYTLER_CUSTO_FIXO_TRANSACAO', 0.0),
    'custo_percentual_transacao' => (float) env('PAYTLER_CUSTO_PERCENTUAL_TRANSACAO', 2.0),

    /*
    |--------------------------------------------------------------------------
    | Rate limit
    |--------------------------------------------------------------------------
    | TPS informado pela Paytler — ajustar via .env. Default conservador.
    */
    'rate_limit_per_second' => (int) env('PAYTLER_RATE_LIMIT_PER_SECOND', 500),
    'rate_limit_per_minute' => (int) env('PAYTLER_RATE_LIMIT_PER_MINUTE', 18000),
    'webhook_rate_limit_per_minute' => (int) env('PAYTLER_WEBHOOK_RATE_LIMIT_PER_MINUTE', 18000),

    /*
    |--------------------------------------------------------------------------
    | Autenticação do webhook inbound
    |--------------------------------------------------------------------------
    | A Paytler envia HTTP Basic no header Authorization: Basic base64(user:pass),
    | definidos no cadastro do webhook (painel dash.paytler.com ou POST webhook-manager).
    | Defina os MESMOS valores aqui. IPs de origem como trava adicional (CSV).
    | Tudo vazio = webhook aberto (não recomendado em produção com cash-in).
    */
    'webhook_username' => env('PAYTLER_WEBHOOK_USERNAME'),
    'webhook_password' => env('PAYTLER_WEBHOOK_PASSWORD'),
    'webhook_allowed_ips' => env('PAYTLER_WEBHOOK_ALLOWED_IPS'),

];
