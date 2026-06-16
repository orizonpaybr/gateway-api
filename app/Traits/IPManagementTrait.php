<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

trait IPManagementTrait
{
    /**
     * Verifica se o IP está autorizado para o usuário
     */
    public static function isIPAllowed(string $clientIP, User $user): bool
    {
        // Recarregar usuário do banco para garantir dados atualizados
        // Isso evita problemas de cache quando IPs são adicionados/removidos
        $user = User::where('username', $user->username)->first();
        if (!$user) {
            Log::error('[IP_MANAGEMENT] Usuário não encontrado ao verificar IP', [
                'client_ip' => $clientIP
            ]);
            return false;
        }

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
        
        // Verificar IPs globais (exato, CIDR ou wildcard)
        if (! empty($globalIPs) && self::checkIPInList($clientIP, $globalIPs)) {
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
            return false;
        }

        $isAllowed = self::checkIPInList($clientIP, $allowedIPs);

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
     * Verifica se IP está dentro de um range CIDR (IPv4 ou IPv6).
     */
    public static function isIPInCIDR(string $ip, string $cidr): bool
    {
        $parts = explode('/', trim($cidr), 2);
        if (count($parts) !== 2 || ! ctype_digit($parts[1])) {
            return false;
        }

        $ipBin = @inet_pton(trim($ip));
        $subnetBin = @inet_pton(trim($parts[0]));
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        // Famílias diferentes (IPv4 x IPv6) nunca casam.
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8; // 32 (IPv4) ou 128 (IPv6)
        $mask = (int) $parts[1];
        if ($mask < 0 || $mask > $maxBits) {
            return false;
        }
        if ($mask === 0) {
            return true;
        }

        $fullBytes = intdiv($mask, 8);
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $mask % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $maskByte = (~((1 << (8 - $remainingBits)) - 1)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $maskByte) === (ord($subnetBin[$fullBytes]) & $maskByte);
    }

    /**
     * Aplica a máscara de prefixo a um endereço binário (inet_pton), zerando os bits de host.
     */
    private static function applyMaskToBinary(string $bin, int $mask): string
    {
        $result = '';
        $total = strlen($bin);

        for ($i = 0; $i < $total; $i++) {
            $bitsForByte = $mask - ($i * 8);

            if ($bitsForByte >= 8) {
                $result .= $bin[$i];
            } elseif ($bitsForByte <= 0) {
                $result .= chr(0);
            } else {
                $maskByte = (~((1 << (8 - $bitsForByte)) - 1)) & 0xFF;
                $result .= chr(ord($bin[$i]) & $maskByte);
            }
        }

        return $result;
    }

    /**
     * Obtém o IP real do cliente de forma segura contra spoofing.
     *
     * Regra: os cabeçalhos de encaminhamento (CF-Connecting-IP / X-Forwarded-For)
     * só são confiados quando a conexão (REMOTE_ADDR) vem de um proxy confiável
     * (Cloudflare). Em conexão direta ao IP de origem, eles são ignorados — assim
     * um atacante não consegue forjar CF-Connecting-IP para furar a allowlist de saque.
     *
     * Observação: se o Nginx tiver real_ip (CF) ativo, ele já reescreve REMOTE_ADDR
     * para o IP real do cliente; nesse caso REMOTE_ADDR não está no range Cloudflare
     * e é retornado diretamente — o que também é correto e seguro.
     */
    public static function getClientIP(?Request $request = null): string
    {
        $request = $request ?? request();

        $remoteAddr = $request->server('REMOTE_ADDR');
        $remoteAddr = is_string($remoteAddr) ? trim($remoteAddr) : '';

        if ($remoteAddr !== '' && self::isTrustedProxy($remoteAddr)) {
            $cf = $request->headers->get('CF-Connecting-IP')
                ?: $request->headers->get('True-Client-IP');
            if (is_string($cf) && filter_var(trim($cf), FILTER_VALIDATE_IP)) {
                return trim($cf);
            }

            $xff = $request->headers->get('X-Forwarded-For');
            if (is_string($xff) && $xff !== '') {
                $first = trim(explode(',', $xff)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }

        if ($remoteAddr !== '' && filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
            return $remoteAddr;
        }

        $laravelIp = $request->ip();

        return is_string($laravelIp) && $laravelIp !== '' ? $laravelIp : '0.0.0.0';
    }

    /**
     * Indica se um IP é um proxy confiável (Cloudflare ou configurado em extra_trusted_proxies).
     */
    public static function isTrustedProxy(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach (self::trustedProxyRanges() as $range) {
            if ($range === '') {
                continue;
            }

            if (str_contains($range, '/')) {
                if (self::isIPInCIDR($ip, $range)) {
                    return true;
                }
            } elseif ($ip === $range) {
                return true;
            }
        }

        return false;
    }

    /**
     * Faixas confiáveis: config/cloudflare.php (override) + baseline embutido (à prova de
     * app não inicializada / config cache ausente) + proxies extras configurados.
     *
     * @return array<int, string>
     */
    private static function trustedProxyRanges(): array
    {
        $ranges = null;
        $extra = [];

        try {
            if (function_exists('config')) {
                $cfg = config('cloudflare.ip_ranges');
                if (is_array($cfg) && $cfg !== []) {
                    $ranges = $cfg;
                }

                $ex = config('cloudflare.extra_trusted_proxies');
                if (is_array($ex)) {
                    $extra = $ex;
                }
            }
        } catch (\Throwable $e) {
            $ranges = null;
        }

        if ($ranges === null) {
            $ranges = self::defaultCloudflareRanges();
        }

        return array_merge($ranges, $extra);
    }

    /**
     * Baseline das faixas Cloudflare (https://www.cloudflare.com/ips/).
     *
     * @return array<int, string>
     */
    private static function defaultCloudflareRanges(): array
    {
        return [
            // IPv4
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
            // IPv6
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
        ];
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
            return self::getClientIP($request instanceof Request ? $request : null);
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
            $ip = self::normalizeAllowedIP(trim($ip));

            if (! self::isValidIP($ip)) {
                return false;
            }

            // Recarregar usuário do banco para garantir dados atualizados
            $user = User::where('username', $user->username)->first();
            if (!$user) {
                Log::error('[IP_MANAGEMENT] Usuário não encontrado ao adicionar IP', [
                    'ip' => $ip
                ]);
                return false;
            }

            $currentIPs = self::parseAllowedIPs($user->ips_saque_permitidos ?? '');
            
            // Verificar se o IP já existe
            if (in_array($ip, $currentIPs)) {
                Log::info('[IP_MANAGEMENT] IP já existe na lista', [
                    'user_id' => $user->user_id,
                    'ip' => $ip,
                    'current_ips' => $currentIPs
                ]);
                return false; // IP já existe
            }

            $currentIPs[] = $ip;
            $ipsJson = json_encode($currentIPs);
            
            Log::info('[IP_MANAGEMENT] Tentando salvar IP', [
                'user_id' => $user->user_id,
                'username' => $user->username,
                'new_ip' => $ip,
                'all_ips' => $currentIPs,
                'ips_json' => $ipsJson,
                'before_save' => $user->ips_saque_permitidos
            ]);

            // Atualizar e salvar usando update direto para garantir persistência
            $updated = \Illuminate\Support\Facades\DB::table('users')
                ->where('username', $user->username)
                ->update(['ips_saque_permitidos' => $ipsJson]);

            // Verificar se realmente salvou
            $user->refresh();
            $afterSave = $user->ips_saque_permitidos;

            Log::info('[IP_MANAGEMENT] Resultado do update', [
                'user_id' => $user->user_id,
                'username' => $user->username,
                'updated_rows' => $updated,
                'after_save' => $afterSave,
                'ips_match' => $afterSave === $ipsJson
            ]);

            if ($afterSave !== $ipsJson) {
                Log::error('[IP_MANAGEMENT] IP não foi salvo corretamente', [
                    'user_id' => $user->user_id,
                    'expected' => $ipsJson,
                    'actual' => $afterSave
                ]);
                return false;
            }

            Log::info('[IP_MANAGEMENT] IP adicionado com sucesso', [
                'user_id' => $user->user_id,
                'username' => $user->username,
                'new_ip' => $ip,
                'all_ips' => $currentIPs,
                'saved_value' => $afterSave
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('[IP_MANAGEMENT] Erro ao adicionar IP', [
                'user_id' => $user->user_id ?? 'unknown',
                'ip' => $ip,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
            $ip = trim($ip);
            if ($ip === '') {
                return false;
            }

            $user = User::where('username', $user->username)->first();
            if (! $user) {
                return false;
            }

            $currentIPs = self::parseAllowedIPs((string) ($user->ips_saque_permitidos ?? ''));
            $normalizedCurrent = array_map(static fn ($currentIP) => trim((string) $currentIP), $currentIPs);

            if (! in_array($ip, $normalizedCurrent, true)) {
                Log::info('[IP_MANAGEMENT] IP não encontrado para remoção', [
                    'user_id' => $user->user_id,
                    'requested_ip' => $ip,
                    'current_ips' => $normalizedCurrent,
                ]);

                return false;
            }

            $newIPs = array_values(array_filter($normalizedCurrent, static function ($currentIP) use ($ip) {
                return $currentIP !== '' && $currentIP !== $ip;
            }));

            $ipsJson = json_encode($newIPs);
            DB::table('users')
                ->where('username', $user->username)
                ->update(['ips_saque_permitidos' => $ipsJson]);

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
     * Normaliza entrada para formato canônico (rede + máscara em CIDR).
     */
    public static function normalizeAllowedIP(string $ip): string
    {
        $ip = trim($ip);

        if (! str_contains($ip, '/')) {
            return $ip;
        }

        $parts = explode('/', $ip, 2);
        if (count($parts) !== 2 || ! ctype_digit($parts[1])) {
            return $ip;
        }

        $bin = @inet_pton($parts[0]);
        if ($bin === false) {
            return $ip;
        }

        $maxBits = strlen($bin) * 8; // 32 (IPv4) ou 128 (IPv6)
        $mask = (int) $parts[1];
        if ($mask < 0 || $mask > $maxBits) {
            return $ip;
        }

        if ($mask === 0) {
            return ($maxBits === 32 ? '0.0.0.0' : '::').'/0';
        }

        $network = @inet_ntop(self::applyMaskToBinary($bin, $mask));
        if ($network === false) {
            return $ip;
        }

        return $network.'/'.$mask;
    }

    /**
     * Valida se um IP, CIDR ou wildcard é permitido na allowlist (IPv4 ou IPv6).
     */
    public static function isValidIP(string $ip): bool
    {
        $ip = trim($ip);

        if ($ip === '') {
            return false;
        }

        if (str_contains($ip, '/')) {
            return self::isValidCidr($ip);
        }

        if (str_contains($ip, '*')) {
            return self::isValidWildcardIPv4($ip);
        }

        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    private static function isValidIPv4Octets(string $ip): bool
    {
        if (! preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/', $ip, $matches)) {
            return false;
        }

        for ($i = 1; $i <= 4; $i++) {
            $octet = (int) $matches[$i];
            if ($octet < 0 || $octet > 255) {
                return false;
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private static function isValidCidr(string $cidr): bool
    {
        $parts = explode('/', trim($cidr), 2);
        if (count($parts) !== 2 || str_contains($parts[0], '*')) {
            return false;
        }

        $mask = $parts[1];
        if (! ctype_digit($mask)) {
            return false;
        }

        $bin = @inet_pton($parts[0]);
        if ($bin === false) {
            return false;
        }

        $maxBits = strlen($bin) * 8; // 32 (IPv4) ou 128 (IPv6)
        $maskInt = (int) $mask;

        return $maskInt >= 0 && $maskInt <= $maxBits;
    }

    private static function isValidWildcardIPv4(string $ip): bool
    {
        if (str_contains($ip, '/')) {
            return false;
        }

        $pattern = str_replace('*', '0', $ip);

        return self::isValidIPv4Octets($pattern)
            && preg_match('/^(\d{1,3}|\*)(\.(\d{1,3}|\*)){3}$/', $ip) === 1;
    }
}
