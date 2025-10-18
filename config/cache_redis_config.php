<?php

// config/cache.php - Configuração Redis otimizada
return [
    'default' => env('CACHE_DRIVER', 'redis'),
    
    'stores' => [
        'redis' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'lock_connection' => 'default',
        ],
        
        // Cache específico para dados de usuário
        'user_cache' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'prefix' => 'user:',
            'ttl' => 300, // 5 minutos
        ],
        
        // Cache para dados de dashboard
        'dashboard_cache' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'prefix' => 'dashboard:',
            'ttl' => 60, // 1 minuto
        ],
        
        // Cache para listagens
        'listings_cache' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'prefix' => 'listings:',
            'ttl' => 300, // 5 minutos
        ],
    ],
    
    'prefix' => env('CACHE_PREFIX', 'gateway_'),
];
