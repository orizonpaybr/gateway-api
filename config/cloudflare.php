<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Faixas de IP da Cloudflare (proxies confiáveis)
    |--------------------------------------------------------------------------
    |
    | Usadas por IPManagementTrait::getClientIP() para decidir se os cabeçalhos
    | de encaminhamento (CF-Connecting-IP / X-Forwarded-For) podem ser confiados.
    |
    | Só confiamos nesses cabeçalhos quando a conexão (REMOTE_ADDR) vem de um
    | range Cloudflare. Em conexão direta (atacante batendo no IP de origem),
    | os cabeçalhos são ignorados — impedindo que a allowlist de saque seja
    | contornada com um CF-Connecting-IP forjado.
    |
    | Fonte oficial: https://www.cloudflare.com/ips/
    | Para sobrescrever sem editar este arquivo, defina CLOUDFLARE_IP_RANGES
    | no .env (lista separada por vírgula).
    |
    */

    'ip_ranges' => array_values(array_filter(array_map('trim', explode(',', (string) env('CLOUDFLARE_IP_RANGES', implode(',', [
        // IPv4 (https://www.cloudflare.com/ips-v4)
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        // IPv6 (https://www.cloudflare.com/ips-v6)
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ])))))),

    /*
    |--------------------------------------------------------------------------
    | Proxies confiáveis adicionais
    |--------------------------------------------------------------------------
    |
    | IPs/CIDRs extras (ex.: um load balancer interno) que também podem
    | encaminhar o IP real do cliente. Deixe vazio se só usa Cloudflare.
    |
    */

    'extra_trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXY_IPS', ''))))),

];
