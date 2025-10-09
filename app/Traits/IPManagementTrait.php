<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Support\Facades\Log;

trait IPManagementTrait
{
    /**
     * Verifica se o IP está autorizado para o usuário
     */
    public static function isIPAllowed(string $clientIP, User $user): bool
    {
        // Buscar IPs globais do banco de dados
        $app = \App\Models\App::first();
        $globalIPs = $app ? ($app->global_ips ?? []) : [];
        
        // Garantir que global_ips seja um array (lidar com string JSON)
        if (!is_array($globalIPs)) {
            if (is_string($globalIPs)) {
                $globalIPs = json_decode($globalIPs, true) ?: [];
            } else {
                $globalIPs = [];
            }
        }
        
        // Verificar se é um IP global primeiro
        if (in_array($clientIP, $globalIPs)) {
            Log::info('[IP_MANAGEMENT] IP global autorizado', [
                'user_id' => $user->user_id,
                'client_ip' => $clientIP,
                'is_global' => true,
                'global_ips' => $globalIPs
            ]);
            return true;
        }

        if (empty($user->ips_saque_permitidos)) {
            Log::warning('[IP_MANAGEMENT] Usuário sem IPs permitidos configurados', [
                'user_id' => $user->user_id,
                'client_ip' => $clientIP
            ]);
            return false;
        }

        $allowedIPs = self::parseAllowedIPs($user->ips_saque_permitidos);
        
        if (empty($allowedIPs)) {
            Log::warning('[IP_MANAGEMENT] Lista de IPs vazia após parsing', [
                'user_id' => $user->user_id,
                'client_ip' => $clientIP,
                'raw_ips' => $user->ips_saque_permitidos
            ]);
            return false;
        }

        $isAllowed = self::checkIPInList($clientIP, $allowedIPs);

        Log::info('[IP_MANAGEMENT] Verificação de IP', [
            'user_id' => $user->user_id,
            'client_ip' => $clientIP,
            'allowed_ips' => $allowedIPs,
            'is_allowed' => $isAllowed
        ]);

        return $isAllowed;
    }

    /**
     * Converte string de IPs para array
     */
    public static function parseAllowedIPs(string $ipsString): array
    {
        if (empty($ipsString)) {
            return [];
        }

        // Suportar diferentes formatos: JSON, CSV, linha por linha
        if (str_starts_with($ipsString, '[') || str_starts_with($ipsString, '{')) {
            // Formato JSON
            $ips = json_decode($ipsString, true);
            return is_array($ips) ? $ips : [];
        }

        // Formato CSV ou linha por linha
        $ips = preg_split('/[,\n\r]+/', $ipsString);
        return array_filter(array_map('trim', $ips));
    }

    /**
     * Verifica se o IP está na lista de permitidos
     */
    public static function checkIPInList(string $clientIP, array $allowedIPs): bool
    {
        foreach ($allowedIPs as $allowedIP) {
            $allowedIP = trim($allowedIP);
            
            if (empty($allowedIP)) {
                continue;
            }

            // Verificação exata
            if ($clientIP === $allowedIP) {
                return true;
            }

            // Verificação de CIDR (ex: 192.168.1.0/24)
            if (str_contains($allowedIP, '/')) {
                if (self::isIPInCIDR($clientIP, $allowedIP)) {
                    return true;
                }
            }

            // Verificação de wildcard (ex: 192.168.1.*)
            if (str_contains($allowedIP, '*')) {
                $pattern = str_replace('*', '.*', preg_quote($allowedIP, '/'));
                if (preg_match('/^' . $pattern . '$/', $clientIP)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Verifica se IP está dentro de um range CIDR
     */
    public static function isIPInCIDR(string $ip, string $cidr): bool
    {
        list($subnet, $mask) = explode('/', $cidr);
        
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - $mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    /**
     * Obtém o IP real do cliente
     */
    public static function getClientIP(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_FORWARDED_FOR',        // Proxy
            'HTTP_FORWARDED',            // Proxy
            'REMOTE_ADDR'                // IP direto
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return request()->ip();
    }

    /**
     * Obtém o IP correto para enviar aos adquirentes baseado no tipo de requisição
     */
    public static function getIPForAcquirer($request): string
    {
        // Verificar se é saque via interface web
        $isInterfaceWeb = false;
        
        // Verificar diferentes formas de acessar o baasPostbackUrl
        if (method_exists($request, 'input')) {
            $isInterfaceWeb = $request->input('baasPostbackUrl') === 'web';
        } elseif (isset($request->baasPostbackUrl)) {
            $isInterfaceWeb = $request->baasPostbackUrl === 'web';
        } elseif (is_object($request) && property_exists($request, 'baasPostbackUrl')) {
            $isInterfaceWeb = $request->baasPostbackUrl === 'web';
        }
        
        if ($isInterfaceWeb) {
            // Para requisições da interface web, usar o primeiro IP dos IPs globais configurados
            $serverIP = self::getServerIPFromConfig();
            Log::info('[IP_MANAGEMENT] Usando IP do servidor configurado para interface web', [
                'server_ip' => $serverIP,
                'is_interface_web' => true
            ]);
            return $serverIP;
        } else {
            // Para requisições de API direta, usar IP real do cliente
            return self::getClientIP();
        }
    }

    /**
     * Obtém o IP do servidor configurado nos IPs globais
     */
    public static function getServerIPFromConfig(): string
    {
        try {
            // Buscar IPs globais do banco de dados
            $app = \App\Models\App::first();
            $globalIPs = $app ? ($app->global_ips ?? []) : [];
            
            // Garantir que global_ips seja um array (lidar com string JSON)
            if (!is_array($globalIPs)) {
                if (is_string($globalIPs)) {
                    $globalIPs = json_decode($globalIPs, true) ?: [];
                } else {
                    $globalIPs = [];
                }
            }
            
            // Se há IPs globais configurados, usar o primeiro
            if (!empty($globalIPs)) {
                $serverIP = trim($globalIPs[0]);
                Log::info('[IP_MANAGEMENT] IP do servidor obtido da configuração', [
                    'server_ip' => $serverIP,
                    'total_global_ips' => count($globalIPs),
                    'all_global_ips' => $globalIPs
                ]);
                return $serverIP;
            }
            
            // Fallback para IP fixo se não houver configuração
            Log::warning('[IP_MANAGEMENT] Nenhum IP global configurado, usando fallback', [
                'fallback_ip' => '54.232.237.217'
            ]);
            return '54.232.237.217';
            
        } catch (\Exception $e) {
            Log::error('[IP_MANAGEMENT] Erro ao obter IP do servidor da configuração', [
                'error' => $e->getMessage(),
                'fallback_ip' => '54.232.237.217'
            ]);
            return '54.232.237.217';
        }
    }

    /**
     * Adiciona um IP à lista de permitidos
     */
    public static function addAllowedIP(User $user, string $ip): bool
    {
        try {
            $currentIPs = self::parseAllowedIPs($user->ips_saque_permitidos ?? '');
            
            // Verificar se o IP já existe
            if (in_array($ip, $currentIPs)) {
                return false; // IP já existe
            }

            $currentIPs[] = $ip;
            $user->ips_saque_permitidos = json_encode($currentIPs);
            $user->save();

            Log::info('[IP_MANAGEMENT] IP adicionado com sucesso', [
                'user_id' => $user->user_id,
                'new_ip' => $ip,
                'all_ips' => $currentIPs
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('[IP_MANAGEMENT] Erro ao adicionar IP', [
                'user_id' => $user->user_id,
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Remove um IP da lista de permitidos
     */
    public static function removeAllowedIP(User $user, string $ip): bool
    {
        try {
            $currentIPs = self::parseAllowedIPs($user->ips_saque_permitidos ?? '');
            $newIPs = array_filter($currentIPs, function($currentIP) use ($ip) {
                return $currentIP !== $ip;
            });

            $user->ips_saque_permitidos = json_encode(array_values($newIPs));
            $user->save();

            Log::info('[IP_MANAGEMENT] IP removido com sucesso', [
                'user_id' => $user->user_id,
                'removed_ip' => $ip,
                'remaining_ips' => $newIPs
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('[IP_MANAGEMENT] Erro ao remover IP', [
                'user_id' => $user->user_id,
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Lista todos os IPs permitidos do usuário
     */
    public static function getAllowedIPs(User $user): array
    {
        return self::parseAllowedIPs($user->ips_saque_permitidos ?? '');
    }

    /**
     * Valida se um IP é válido
     */
    public static function isValidIP(string $ip): bool
    {
        // Verificar IP simples
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        // Verificar CIDR
        if (str_contains($ip, '/')) {
            list($subnet, $mask) = explode('/', $ip);
            if (filter_var($subnet, FILTER_VALIDATE_IP) && is_numeric($mask) && $mask >= 0 && $mask <= 32) {
                return true;
            }
        }

        // Verificar wildcard
        if (str_contains($ip, '*')) {
            $pattern = str_replace('*', '.*', preg_quote($ip, '/'));
            return preg_match('/^' . $pattern . '$/', '192.168.1.1') !== false;
        }

        return false;
    }
}
