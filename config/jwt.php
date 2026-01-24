<?php

return [
    /*
    |--------------------------------------------------------------------------
    | JWT Secret
    |--------------------------------------------------------------------------
    |
    | Chave secreta usada para assinar os tokens JWT.
    | IMPORTANTE: Deve ser uma string longa e aleatória.
    | Use: php artisan jwt:secret para gerar uma nova chave.
    |
    */
    'secret' => env('JWT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | JWT Algorithm
    |--------------------------------------------------------------------------
    |
    | Algoritmo de assinatura do JWT.
    | Opções: HS256, HS384, HS512, RS256, RS384, RS512
    | HS256 é o mais comum e recomendado para a maioria dos casos.
    |
    */
    'algorithm' => env('JWT_ALGORITHM', 'HS256'),

    /*
    |--------------------------------------------------------------------------
    | Token Expiration
    |--------------------------------------------------------------------------
    |
    | Tempo de expiração do token em horas.
    | Padrão: 24 horas
    |
    */
    'expiration_hours' => env('JWT_EXPIRATION_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Issuer
    |--------------------------------------------------------------------------
    |
    | Identificador do emissor do token (claim "iss").
    | Geralmente o nome da aplicação.
    |
    */
    'issuer' => env('JWT_ISSUER', env('APP_NAME', 'Gateway API')),

    /*
    |--------------------------------------------------------------------------
    | Temporary Token Expiration
    |--------------------------------------------------------------------------
    |
    | Tempo de expiração de tokens temporários (2FA) em minutos.
    | Padrão: 5 minutos
    |
    */
    'temp_token_minutes' => env('JWT_TEMP_TOKEN_MINUTES', 5),
];
