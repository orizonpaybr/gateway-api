<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Limite máximo por saque PIX
    |--------------------------------------------------------------------------
    |
    | Valor máximo permitido por operação de saque PIX (em reais).
    | Valores acima deste limite são rejeitados no backend e no frontend.
    |
    */

    'limite_maximo_por_saque' => (float) (env('SAQUE_LIMITE_MAXIMO_POR_SAQUE', 100000)),

    /*
    |--------------------------------------------------------------------------
    | Rate limit — POST /api/status
    |--------------------------------------------------------------------------
    */

    'status_check_rate_limit_per_minute' => (int) env('STATUS_CHECK_RATE_LIMIT_PER_MINUTE', 120),

];
