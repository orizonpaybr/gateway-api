<?php

namespace App\Helpers;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Helper para usar SCAN em vez de KEYS no Redis.
 * KEYS é O(N) e pode bloquear o servidor; SCAN é iterativo e não bloqueia.
 */
class RedisScanner
{
    /** Tamanho de cada página do SCAN (sugestão ao Redis) */
    private const SCAN_COUNT = 100;

    /** Tamanho do chunk para DEL (evita comando muito grande) */
    private const DEL_CHUNK_SIZE = 100;

    /**
     * Percorre as chaves que batem com o padrão usando SCAN e remove cada lote.
     * Uso: invalidação de cache por prefixo sem bloquear o Redis.
     *
     * @param Connection|string $connection Conexão Redis (instância ou nome)
     * @param string $pattern Padrão (ex: "laravel_cache_*")
     * @return int Número de chaves removidas
     */
    public static function scanAndDelete($connection, string $pattern): int
    {
        $redis = $connection instanceof Connection
            ? $connection
            : Redis::connection($connection);

        if (!method_exists($redis, 'scan')) {
            // Fallback para conexões que não expõem scan (ex.: driver diferente)
            $keys = $redis->keys($pattern);
            if (!empty($keys) && is_array($keys)) {
                $chunks = array_chunk($keys, self::DEL_CHUNK_SIZE);
                foreach ($chunks as $chunk) {
                    $redis->del($chunk);
                }
                return count($keys);
            }
            return 0;
        }

        $totalDeleted = 0;
        $cursor = 0;

        do {
            $ret = $redis->scan($cursor, [
                'match' => $pattern,
                'count'  => self::SCAN_COUNT,
            ]);

            if ($ret === false) {
                break;
            }

            [, $keys] = $ret;
            if (!empty($keys) && is_array($keys)) {
                $chunks = array_chunk($keys, self::DEL_CHUNK_SIZE);
                foreach ($chunks as $chunk) {
                    $redis->del($chunk);
                }
                $totalDeleted += count($keys);
            }
        } while ($cursor != 0);

        return $totalDeleted;
    }

    /**
     * Conta chaves que batem com o padrão usando SCAN (sem carregar todas na memória).
     *
     * @param Connection|string $connection Conexão Redis (instância ou nome)
     * @param string $pattern Padrão (ex: "laravel_cache_*")
     * @return int Número de chaves
     */
    public static function scanCount($connection, string $pattern): int
    {
        $redis = $connection instanceof Connection
            ? $connection
            : Redis::connection($connection);

        if (!method_exists($redis, 'scan')) {
            $keys = $redis->keys($pattern);
            return is_array($keys) ? count($keys) : 0;
        }

        $total = 0;
        $cursor = 0;

        do {
            $ret = $redis->scan($cursor, [
                'match' => $pattern,
                'count'  => self::SCAN_COUNT,
            ]);

            if ($ret === false) {
                break;
            }

            [, $keys] = $ret;
            if (!empty($keys) && is_array($keys)) {
                $total += count($keys);
            }
        } while ($cursor != 0);

        return $total;
    }
}
