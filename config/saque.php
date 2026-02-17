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

];
