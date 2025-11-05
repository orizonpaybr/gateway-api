<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Redis;

/**
 * Service para centralizar cache keys
 * Segue padrão: namespace:entity:identifier:details
 */
class CacheKeyService
{
    /**
     * Cache key para usuário admin
     */
    public static function adminUser(int $userId, bool $withRelations = false): string
    {
        $suffix = $withRelations ? 'full' : 'basic';
        return "admin:user:{$userId}:{$suffix}";
    }
    
    /**
     * Cache key para estatísticas de dashboard
     */
    public static function adminDashboardStats(string $periodo, Carbon $inicio, Carbon $fim): string
    {
        return "admin:dashboard:stats:{$periodo}:{$inicio->format('Ymd')}:{$fim->format('Ymd')}";
    }
    
    /**
     * Cache key para estatísticas de usuários
     */
    public static function adminUsersStats(): string
    {
        return 'admin:users:stats';
    }
    
    /**
     * Cache key para lista de usuários
     */
    public static function adminUsersList(array $filters = []): string
    {
        $hash = md5(json_encode($filters));
        return "admin:users:list:{$hash}";
    }
    
    /**
     * Cache key para XDPag config
     */
    public static function xdpagConfig(): string
    {
        return 'xdpag:config';
    }
    
    /**
     * Cache key para saldo total de carteiras
     */
    public static function totalWalletsBalance(): string
    {
        return 'total:wallets:balance';
    }
    
    /**
     * Limpar cache de usuário específico
     * Usa Redis explicitamente (seguindo padrão do projeto)
     */
    public static function forgetUser(int $userId): void
    {
        try {
            $key1 = self::adminUser($userId, true);
            $key2 = self::adminUser($userId, false);
            Redis::del($key1);
            Redis::del($key2);
            // Fallback para Cache facade
            \Illuminate\Support\Facades\Cache::forget($key1);
            \Illuminate\Support\Facades\Cache::forget($key2);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Erro ao limpar cache Redis de usuário', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Limpar cache de estatísticas de usuários
     * Usa Redis explicitamente (seguindo padrão do projeto)
     */
    public static function forgetUsersStats(): void
    {
        try {
            $key = self::adminUsersStats();
            Redis::del($key);
            // Fallback para Cache facade
            \Illuminate\Support\Facades\Cache::forget($key);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Erro ao limpar cache Redis de estatísticas', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Limpar cache de dashboard por período
     * Usa Redis explicitamente (seguindo padrão do projeto)
     */
    public static function forgetDashboardStats(?string $periodo = null): void
    {
        try {
            if ($periodo) {
                $periodos = [$periodo];
            } else {
                // Limpar apenas períodos relevantes
                $periodos = ['hoje', 'ontem', '7dias', '30dias', 'mes_atual', 'mes_anterior', 'tudo'];
            }
            
            // Limpar usando padrão de chaves Redis
            $pattern = 'admin:dashboard:stats:*';
            $keys = Redis::keys($pattern);
            
            if (!empty($keys)) {
                Redis::del($keys);
            }
            
            // Fallback para Cache facade
            foreach ($periodos as $p) {
                if (method_exists(\Illuminate\Support\Facades\Cache::getStore(), 'tags')) {
                    \Illuminate\Support\Facades\Cache::tags(['admin:dashboard', $p])->flush();
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Erro ao limpar cache Redis de dashboard', [
                'error' => $e->getMessage()
            ]);
        }
    }
}

