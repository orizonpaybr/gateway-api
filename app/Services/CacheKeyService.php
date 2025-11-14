<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\{Cache, Log};

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
     * Cache key para transações recentes do admin
     */
    public static function adminRecentTransactions(?string $type, ?string $status, int $limit): string
    {
        $typeKey = $type ?? 'all';
        $statusKey = $status ?? 'all';
        return "admin:transactions:recent:{$typeKey}:{$statusKey}:{$limit}";
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
     * Usa Cache facade (padronizado - usa Redis se configurado)
     */
    public static function forgetUser(int $userId): void
    {
        try {
            $key1 = self::adminUser($userId, true);
            $key2 = self::adminUser($userId, false);
            Cache::forget($key1);
            Cache::forget($key2);
        } catch (\Exception $e) {
            Log::warning('Erro ao limpar cache de usuário', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Limpar cache de estatísticas de usuários
     * Usa Cache facade (padronizado - usa Redis se configurado)
     */
    public static function forgetUsersStats(): void
    {
        try {
            $key = self::adminUsersStats();
            Cache::forget($key);
        } catch (\Exception $e) {
            Log::warning('Erro ao limpar cache de estatísticas', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Limpar cache de dashboard por período
     * Usa Cache facade (padronizado - usa Redis se configurado)
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
            
            // Limpar usando tags se suportado pelo driver
            $store = Cache::getStore();
            
            foreach ($periodos as $p) {
                if (method_exists($store, 'tags')) {
                    try {
                        Cache::tags(['admin:dashboard', $p])->flush();
                    } catch (\Exception $e) {
                        // Tags podem não ser suportadas por todos os drivers
                        Log::debug('Tags não suportadas pelo driver de cache', [
                            'error' => $e->getMessage()
                        ]);
                    }
                } else {
                    // Fallback: limpar cache manualmente para períodos conhecidos
                    // Nota: sem tags, não podemos limpar por padrão de forma eficiente
                    // O cache expirará naturalmente pelo TTL
                    Log::debug('Driver de cache não suporta tags, cache expirará pelo TTL');
                }
            }
        } catch (\Exception $e) {
            Log::warning('Erro ao limpar cache de dashboard', [
                'error' => $e->getMessage()
            ]);
        }
    }
}

