<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * Service para coletar métricas de cache Redis
 * 
 * Integra com o painel admin existente
 * As métricas podem ser exibidas no Dashboard Admin atual
 * 
 * IMPORTANTE: Usa a mesma conexão Redis que o cache utiliza
 * (conexão 'cache' do database.php, geralmente database 1)
 */
class CacheMetricsService
{
    /**
     * Obter a conexão Redis usada pelo cache
     * 
     * O cache Laravel usa a conexão definida em config/cache.php
     * que por padrão é 'cache' (database 1), não 'default' (database 0)
     * 
     * @return \Illuminate\Redis\Connections\Connection
     */
    private function getCacheRedisConnection()
    {
        // Obter o nome da conexão do cache store
        // config/cache.php define: 'connection' => env('REDIS_CACHE_CONNECTION', 'cache')
        $cacheConnection = config('cache.stores.redis.connection', 'cache');
        
        // Usar a conexão 'cache' explicitamente
        // Isso garante que estamos no mesmo database que o cache utiliza
        return Redis::connection($cacheConnection);
    }

    /**
     * Obter o prefixo usado pelo cache
     * 
     * @return string
     */
    private function getCachePrefix(): string
    {
        return config('cache.prefix', '');
    }

    /**
     * Aplicar prefixo do cache a um padrão de chave
     * 
     * @param string $pattern
     * @return string
     */
    private function applyCachePrefix(string $pattern): string
    {
        $prefix = $this->getCachePrefix();
        
        if (empty($prefix)) {
            return $pattern;
        }
        
        // Se o padrão já começa com o prefixo, retornar como está
        if (str_starts_with($pattern, $prefix)) {
            return $pattern;
        }
        
        // Aplicar prefixo ao padrão
        return $prefix . $pattern;
    }

    /**
     * Obter métricas básicas do Redis
     * 
     * @return array
     */
    public function getCacheMetrics(): array
    {
        try {
            // Usar a mesma conexão Redis que o cache utiliza
            $redis = $this->getCacheRedisConnection();
            
            // Informações básicas do Redis
            $info = $redis->info('stats');
            $memory = $redis->info('memory');
            
            // Contar chaves de cache do sistema
            $cacheKeys = $this->countCacheKeys();
            
            return [
                'redis_connected' => true,
                'total_commands_processed' => (int) ($info['total_commands_processed'] ?? 0),
                'keyspace_hits' => (int) ($info['keyspace_hits'] ?? 0),
                'keyspace_misses' => (int) ($info['keyspace_misses'] ?? 0),
                'used_memory_human' => $memory['used_memory_human'] ?? '0B',
                'used_memory' => (int) ($memory['used_memory'] ?? 0),
                'cache_keys_count' => $cacheKeys,
                'hit_rate' => $this->calculateHitRate($info),
            ];
        } catch (\Exception $e) {
            Log::warning('Erro ao obter métricas do Redis', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return [
                'redis_connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Obter métricas de cache financeiro específico
     * 
     * @return array
     */
    public function getFinancialCacheMetrics(): array
    {
        try {
            // Usar a mesma conexão Redis que o cache utiliza
            $redis = $this->getCacheRedisConnection();
            
            // Aplicar prefixo do cache aos padrões
            $financialPattern = $this->applyCachePrefix('financial:*');
            $walletsPattern = $this->applyCachePrefix('financial:wallets:*');
            $statsPattern = $this->applyCachePrefix('financial:*:stats:*');
            
            $financialCount = $this->countKeysByPattern($redis, $financialPattern);
            $walletsCount = $this->countKeysByPattern($redis, $walletsPattern);
            $statsCount = $this->countKeysByPattern($redis, $statsPattern);
            
            return [
                'total_financial_keys' => $financialCount,
                'wallets_keys' => $walletsCount,
                'stats_keys' => $statsCount,
            ];
        } catch (\Exception $e) {
            Log::warning('Erro ao obter métricas de cache financeiro', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Contar chaves usando KEYS
     * 
     * Nota: KEYS pode ser lento em produção com muitas chaves, mas é mais confiável
     * Para produção com muitas chaves, considere usar um contador incremental
     * 
     * @param \Illuminate\Redis\Connections\Connection $redis
     * @param string $pattern
     * @return int
     */
    private function countKeysByPattern(\Illuminate\Redis\Connections\Connection $redis, string $pattern): int
    {
        try {
            // Usar KEYS diretamente - mais simples e confiável
            // Em produção com muitas chaves, considere desabilitar esta funcionalidade
            // ou usar um contador incremental mantido pelo sistema
            $keys = $redis->keys($pattern);
            
            if (is_array($keys)) {
                return count($keys);
            }
            
            return 0;
        } catch (\Exception $e) {
            Log::warning('Erro ao contar chaves Redis', [
                'pattern' => $pattern,
                'error' => $e->getMessage(),
            ]);
            
            // Retornar 0 em caso de erro para não quebrar a aplicação
            return 0;
        }
    }

    /**
     * Calcular taxa de acerto (hit rate) do cache
     * 
     * @param array $info
     * @return float
     */
    private function calculateHitRate(array $info): float
    {
        $hits = (int) ($info['keyspace_hits'] ?? 0);
        $misses = (int) ($info['keyspace_misses'] ?? 0);
        $total = $hits + $misses;
        
        if ($total === 0) {
            return 0.0;
        }
        
        return round(($hits / $total) * 100, 2);
    }

    /**
     * Contar chaves de cache do sistema
     * 
     * @return int
     */
    private function countCacheKeys(): int
    {
        try {
            // Usar a mesma conexão Redis que o cache utiliza
            $redis = $this->getCacheRedisConnection();
            
            // Padrões de chaves do sistema (sem prefixo, será aplicado depois)
            $prefixes = [
                'financial:*',
                'admin:*',
                'user:*',
                'app:*',
                'qrcodes:*',
                'notif_pref:*',
            ];
            
            $total = 0;
            foreach ($prefixes as $pattern) {
                $patternWithPrefix = $this->applyCachePrefix($pattern);
                $total += $this->countKeysByPattern($redis, $patternWithPrefix);
            }
            
            return $total;
        } catch (\Exception $e) {
            Log::warning('Erro ao contar chaves de cache do sistema', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Limpar cache financeiro (útil para admin)
     * 
     * @return bool
     */
    public function clearFinancialCache(): bool
    {
        try {
            // Usar a mesma conexão Redis que o cache utiliza
            $redis = $this->getCacheRedisConnection();
            
            // Aplicar prefixo do cache
            $pattern = $this->applyCachePrefix('financial:*');
            $keys = $redis->keys($pattern);
            
            if (!empty($keys) && is_array($keys)) {
                // Usar array_chunk para evitar problemas com muitas chaves
                $chunks = array_chunk($keys, 100);
                foreach ($chunks as $chunk) {
                    $redis->del($chunk);
                }
            }
            
            Log::info('Cache financeiro limpo manualmente', [
                'keys_count' => is_array($keys) ? count($keys) : 0,
                'pattern' => $pattern,
            ]);
            return true;
        } catch (\Exception $e) {
            Log::error('Erro ao limpar cache financeiro', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }
}

