<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Helper para análise de queries SQL
 * 
 * Útil para desenvolvimento e debugging de performance
 * 
 * Uso:
 * QueryAnalyzer::explain($query);
 * QueryAnalyzer::logSlowQueries(1000); // Log queries > 1s
 */
class QueryAnalyzer
{
    /**
     * Executa EXPLAIN em uma query
     * 
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @return array
     */
    public static function explain($query): array
    {
        try {
            $sql = $query->toSql();
            $bindings = $query->getBindings();
            
            // Substituir placeholders pelos valores reais para análise
            $explainSql = vsprintf(str_replace('?', '%s', $sql), array_map(function ($binding) {
                return is_string($binding) ? "'{$binding}'" : $binding;
            }, $bindings));
            
            $explainQuery = "EXPLAIN {$explainSql}";
            $results = DB::select($explainQuery);
            
            return [
                'sql' => $sql,
                'bindings' => $bindings,
                'explain' => $results,
            ];
        } catch (\Exception $e) {
            Log::error('Erro ao executar EXPLAIN', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Loga queries lentas automaticamente
     * 
     * @param int $thresholdMs Threshold em milissegundos
     */
    public static function logSlowQueries(int $thresholdMs = 1000): void
    {
        DB::listen(function ($query) use ($thresholdMs) {
            $time = $query->time;
            
            if ($time > $thresholdMs) {
                Log::warning('Query lenta detectada', [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'time_ms' => $time,
                    'threshold_ms' => $thresholdMs,
                ]);
            }
        });
    }

    /**
     * Analisa e retorna estatísticas de uma query
     * 
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     * @return array
     */
    public static function analyze($query): array
    {
        $explain = self::explain($query);
        
        if (empty($explain)) {
            return [];
        }

        $analysis = [
            'sql' => $explain['sql'],
            'warnings' => [],
            'suggestions' => [],
        ];

        foreach ($explain['explain'] as $row) {
            $row = (array) $row;
            
            // Verificar se está usando índice
            if ($row['key'] === null && $row['type'] !== 'const') {
                $analysis['warnings'][] = 'Query não está usando índice';
                $analysis['suggestions'][] = 'Considere adicionar índice na(s) coluna(s) usada(s) no WHERE/ORDER BY';
            }

            // Verificar tipo de join
            if (isset($row['type']) && in_array($row['type'], ['ALL', 'index'])) {
                $analysis['warnings'][] = "Tipo de scan: {$row['type']} - pode ser lento em tabelas grandes";
            }

            // Verificar rows examinadas
            if (isset($row['rows']) && $row['rows'] > 10000) {
                $analysis['warnings'][] = "Muitas linhas examinadas: {$row['rows']}";
                $analysis['suggestions'][] = 'Considere adicionar filtros ou índices mais específicos';
            }
        }

        return $analysis;
    }
}

