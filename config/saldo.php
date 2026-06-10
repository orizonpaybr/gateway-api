<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Consulta de saldo via API (GET /api/wallet/balance)
    |--------------------------------------------------------------------------
    */

    /** Limite de requisições bem-sucedidas por minuto, por token (conta). */
    'balance_check_rate_limit_per_minute' => (int) env('BALANCE_CHECK_RATE_LIMIT_PER_MINUTE', 60),

    /** TTL do cache da resposta completa (segundos). Reduz carga no banco em polling. */
    'balance_cache_ttl_seconds' => (int) env('BALANCE_CACHE_TTL_SECONDS', 10),

    /** Máximo de respostas de erro (4xx/5xx) por IP antes de bloqueio temporário. */
    'balance_failure_max_attempts_per_ip' => (int) env('BALANCE_FAILURE_MAX_ATTEMPTS_PER_IP', 3),

    /** Tempo (segundos) em que o IP permanece bloqueado após exceder tentativas falhas. */
    'balance_failure_decay_seconds' => (int) env('BALANCE_FAILURE_DECAY_SECONDS', 900),
];
