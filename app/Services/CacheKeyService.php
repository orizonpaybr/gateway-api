<?php

namespace App\Services;

use App\Helpers\RedisScanner;
use Carbon\Carbon;
use Illuminate\Support\Facades\{Cache, Log, Redis};

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
     * Cache key para Pagar.me config
     * Nota: XDPag foi removido, mantido apenas para compatibilidade se necessário
     */
    public static function pagarmeConfig(): string
    {
        return 'pagarme:config';
    }
    
    /**
     * Cache key para saldo total de carteiras
     */
    public static function totalWalletsBalance(): string
    {
        return 'total:wallets:balance';
    }
    
    /**
     * Cache key para lista de gerentes
     */
    public static function managersList(array $filters = []): string
    {
        $hash = md5(json_encode($filters));
        return "admin:managers:list:{$hash}";
    }
    
    /**
     * Cache key para estatísticas de gerentes
     */
    public static function managersStats(): string
    {
        return 'admin:managers:stats';
    }
    
    /**
     * Cache key para lista de adquirentes
     */
    public static function acquirersList(array $filters = []): string
    {
        $hash = md5(json_encode($filters));
        return "admin:acquirers:list:{$hash}";
    }
    
    /**
     * Cache key para estatísticas de adquirentes
     */
    public static function acquirersStats(): string
    {
        return 'admin:acquirers:stats';
    }


    /** TTL em segundos: dados do programa Partners por usuário (link, saldo, indicados, ganhos) */
    public const TTL_AFFILIATE_DATA = 15;

    /**
     * Cache key para dados do programa Partners por usuário
     */
    public static function affiliateData(int $userId): string
    {
        return "affiliate:data:{$userId}";
    }

    /**
     * Limpar cache do programa Partners de um usuário
     */
    public static function forgetAffiliateUser(int $userId): void
    {
        try {
            Cache::forget(self::affiliateData($userId));
            Log::debug('Cache do programa Partners invalidado', ['user_id' => $userId]);
        } catch (\Exception $e) {
            Log::warning('Erro ao limpar cache do programa Partners', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // --- Gamificação (Jornada Orizon) - padrão namespace:entity:identifier ---

    /** TTL em segundos: lista de níveis (muda raramente) */
    public const TTL_GAMIFICATION_NIVEIS = 3600;

    /** TTL em segundos: página Jornada por usuário (igual à sidebar, invalidado ao processar depósito) */
    public const TTL_GAMIFICATION_JOURNEY = 180;

    /** TTL em segundos: sidebar por usuário (invalidado ao processar depósito) */
    public const TTL_GAMIFICATION_SIDEBAR = 180;

    /**
     * Cache key para lista de níveis de gamificação (compartilhada)
     */
    public static function gamificationNiveis(): string
    {
        return 'gamification:niveis:all';
    }

    /**
     * Cache key para dados da página Jornada por usuário
     */
    public static function gamificationJourney(int $userId): string
    {
        return "gamification_data_user_{$userId}";
    }

    /**
     * Cache key para dados de gamificação da sidebar por usuário
     */
    public static function gamificationSidebar(int $userId): string
    {
        return "sidebar_gamification_user_{$userId}";
    }

    /**
     * Limpar cache de níveis de gamificação (ex.: após atualizar níveis no admin)
     */
    public static function forgetGamificationNiveis(): void
    {
        try {
            Cache::forget(self::gamificationNiveis());
            Log::debug('Cache de níveis de gamificação invalidado');
        } catch (\Exception $e) {
            Log::warning('Erro ao limpar cache de níveis de gamificação', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Limpar cache de gamificação de um usuário
     * Chamado quando o usuário tem novo depósito processado.
     */
    public static function forgetGamificationUser(int $userId): void
    {
        try {
            Cache::forget(self::gamificationJourney($userId));
            Cache::forget(self::gamificationSidebar($userId));
            Log::debug('Cache de gamificação do usuário invalidado', ['user_id' => $userId]);
        } catch (\Exception $e) {
            Log::warning('Erro ao limpar cache de gamificação do usuário', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Limpar cache de gamificação (níveis + todos os usuários, ex.: ao atualizar nível no admin)
     * Com Redis, limpa por padrão; sem Redis, limpa apenas níveis
     */
    public static function forgetGamificationAll(): void
    {
        try {
            Cache::forget(self::gamificationNiveis());
            if (config('cache.default') === 'redis') {
                $cacheConnection = config('cache.stores.redis.connection', 'cache');
                $prefix = config('cache.prefix', '');
                $pattern1 = (!empty($prefix) ? $prefix . ':' : '') . 'gamification_data_user_*';
                RedisScanner::scanAndDelete($cacheConnection, $pattern1);
                $pattern2 = (!empty($prefix) ? $prefix . ':' : '') . 'sidebar_gamification_user_*';
                RedisScanner::scanAndDelete($cacheConnection, $pattern2);
            }
            Log::info('Cache de gamificação (niveis e usuários) invalidado');
        } catch (\Exception $e) {
            Log::warning('Erro ao limpar cache de gamificação', ['error' => $e->getMessage()]);
        }
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
                $periodos = ['hoje', 'ontem', '7dias', '30dias', 'tudo'];
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
    
    /**
     * Limpa o cache das transações recentes do dashboard admin
     */
    public static function forgetAdminRecentTransactions(?string $type = null, ?string $status = null, ?int $limit = null): void
    {
        try {
            $types = $type !== null ? [$type] : ['deposit', 'withdraw', null];
            $statuses = $status !== null ? [$status] : [null, 'PAID_OUT', 'PENDING', 'COMPLETED', 'CANCELLED', 'REJECTED'];
            $limits = $limit !== null ? [$limit] : [8, 10, 20, 50, 100];
            
            foreach ($types as $typeOption) {
                foreach ($statuses as $statusOption) {
                    foreach ($limits as $limitOption) {
                        $cacheKey = self::adminRecentTransactions($typeOption, $statusOption, $limitOption);
                        Cache::forget($cacheKey);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Erro ao limpar cache de transações recentes do admin', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Limpar cache de gerentes
     * 
     * Limpa tanto o cache de estatísticas quanto todas as listas de gerentes
     * Como o cache de listas usa hash baseado em filtros, precisamos invalidar
     * usando um padrão ou limpar manualmente.
     */
    public static function forgetManagers(): void
    {
        try {
            // Limpar cache de estatísticas
            Cache::forget(self::managersStats());
            
            // Limpar cache de listas de gerentes usando Redis (SCAN para não bloquear)
            if (config('cache.default') === 'redis') {
                try {
                    $cacheConnection = config('cache.stores.redis.connection', 'cache');
                    $prefix = config('cache.prefix', '');
                    $pattern = !empty($prefix) ? $prefix . 'admin:managers:list:*' : 'admin:managers:list:*';
                    $removed = RedisScanner::scanAndDelete($cacheConnection, $pattern);
                    if ($removed > 0) {
                        Log::info('Cache de listas de gerentes limpo via Redis', [
                            'keys_removed' => $removed
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('Erro ao limpar cache de listas de gerentes via Redis', [
                        'error' => $e->getMessage()
                    ]);
                }
            } else {
                // Se não for Redis, tentar limpar usando flush se disponível
                // ou deixar o TTL expirar (2 minutos)
                Log::info('Cache de gerentes invalidado (não-Redis, TTL expirará em 2 minutos)');
            }
            
            Log::info('Cache de gerentes invalidado');
        } catch (\Exception $e) {
            Log::warning('Erro ao limpar cache de gerentes', ['error' => $e->getMessage()]);
        }
    }
}

