<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class DashboardService
{
    /**
     * Cache TTL padrão para dados de dashboard (1 minuto)
     */
    const CACHE_TTL_DASHBOARD = 60;

    /**
     * Cache TTL para estatísticas mensais (5 minutos)
     */
    const CACHE_TTL_STATS = 300;

    /**
     * Obter estatísticas do dashboard (saldo, entradas, saídas, fluxo líquido em splits_mes)
     * 
     * @param string $username
     * @return array
     */
    public function getDashboardStats(string $username): array
    {
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();
        
        $cacheKey = sprintf('dashboard:stats:%s:%s', $username, now()->format('Y-m-d'));
        
        return Cache::remember($cacheKey, self::CACHE_TTL_STATS, function () use ($username, $startOfMonth, $endOfMonth) {
            $custoTreeal = (float) config('treeal.custo_fixo_transacao', 0.05);
            $custoSimpay = (float) config('simpay.custo_fixo_transacao', 0.035);
            $custoFyhub = (float) config('fyhub.custo_fixo_transacao', 0.04);

            $custoSaqueExpr = \App\Helpers\CustoAdquirentePixHelper::sqlCustoPorTransacaoExpr('amount', true);

            // Query única otimizada usando UNION ALL
            // Lucro líquido = taxa − custo adquirente (taxa explícita na linha ou custo por executor_ordem).
            $statsQuery = "
                SELECT 
                    'deposito' as tipo,
                    SUM(CASE WHEN status IN ('PAID_OUT', 'COMPLETED') THEN amount ELSE 0 END) as total_pago,
                    SUM(CASE WHEN status IN ('PAID_OUT', 'COMPLETED') THEN (
                        taxa_cash_in -
                        CASE
                            WHEN taxa_pix_cash_in_adquirente IS NOT NULL AND taxa_pix_cash_in_adquirente > 0
                            THEN taxa_pix_cash_in_adquirente
                            WHEN executor_ordem = 'treeal' THEN {$custoTreeal}
                            WHEN executor_ordem = 'fyhub' THEN {$custoFyhub}
                            WHEN executor_ordem = 'simpay' OR executor_ordem = 'Adquirente PIX' OR adquirente_ref = 'Adquirente PIX'
                            THEN {$custoSimpay}
                            ELSE 0
                        END
                    ) ELSE 0 END) as total_taxa
                FROM solicitacoes 
                WHERE user_id = ? AND date BETWEEN ? AND ?
                
                UNION ALL
                
                SELECT 
                    'saque' as tipo,
                    SUM(CASE WHEN status IN ('PAID_OUT', 'COMPLETED') THEN amount ELSE 0 END) as total_pago,
                    COALESCE(SUM(CASE WHEN status IN ('PAID_OUT', 'COMPLETED') THEN taxa_cash_out ELSE 0 END), 0)
                    - COALESCE(SUM(CASE WHEN status IN ('PAID_OUT', 'COMPLETED') THEN {$custoSaqueExpr} ELSE 0 END), 0) as total_taxa
                FROM solicitacoes_cash_out 
                WHERE user_id = ? AND date BETWEEN ? AND ?
            ";

            $results = DB::select($statsQuery, [
                $username, $startOfMonth, $endOfMonth,
                $username, $startOfMonth, $endOfMonth,
            ]);

            $depositos = $results[0] ?? (object)['total_pago' => 0, 'total_taxa' => 0];
            $saques = $results[1] ?? (object)['total_pago' => 0, 'total_taxa' => 0];

            // Buscar saldo disponível = saldo principal + saldo de afiliados (otimizado)
            $row = DB::table('users')
                ->where('username', $username)
                ->first(['saldo', 'saldo_afiliado']);
            $saldoDisponivel = $row ? ((float) ($row->saldo ?? 0) + (float) ($row->saldo_afiliado ?? 0)) : 0;

            $entradasMes = (float) $depositos->total_pago;
            $saidasMes = (float) $saques->total_pago;
            // `splits_mes`: fluxo líquido no mês (entradas − saídas), mesmo critério dos totais PIX acima.
            $splitsMes = $entradasMes - $saidasMes;

            return [
                'saldo_disponivel' => (float) $saldoDisponivel,
                'entradas_mes' => $entradasMes,
                'saidas_mes' => $saidasMes,
                'splits_mes' => (float) $splitsMes,
                'periodo' => [
                    'inicio' => $startOfMonth->format('Y-m-d'),
                    'fim' => $endOfMonth->format('Y-m-d'),
                ],
            ];
        });
    }

    /**
     * Calcular intervalo de datas baseado no período
     * 
     * @param string $periodo
     * @return array
     */
    public function calculateDateRange(string $periodo): array
    {
        $now = Carbon::now('America/Sao_Paulo');
        
        return match($periodo) {
            'hoje' => [
                'inicio' => $now->copy()->startOfDay(),
                'fim' => $now->copy()->endOfDay()
            ],
            'ontem' => [
                'inicio' => $now->copy()->subDay()->startOfDay(),
                'fim' => $now->copy()->subDay()->endOfDay()
            ],
            '7dias' => [
                'inicio' => $now->copy()->subDays(6)->startOfDay(),
                'fim' => $now->copy()->endOfDay()
            ],
            '30dias' => [
                'inicio' => $now->copy()->subDays(29)->startOfDay(),
                'fim' => $now->copy()->endOfDay()
            ],
            default => [
                'inicio' => $now->copy()->startOfDay(),
                'fim' => $now->copy()->endOfDay()
            ]
        };
    }

    /**
     * Obter resumo de transações (8 cards) com query única otimizada
     * 
     * @param string $username
     * @param string $periodo
     * @return array
     */
    public function getTransactionSummary(string $username, string $periodo): array
    {
        $dates = $this->calculateDateRange($periodo);
        
        $cacheKey = sprintf('dashboard:summary:%s:%s:%s:%s', 
            $username, $periodo, 
            $dates['inicio']->format('YmdHis'), 
            $dates['fim']->format('YmdHis')
        );
        
        return Cache::remember($cacheKey, self::CACHE_TTL_DASHBOARD, function () use ($username, $dates, $periodo) {
            // Query única otimizada para todas as estatísticas
            $summaryQuery = "
                SELECT 
                    COUNT(CASE WHEN tipo = 'deposito' AND status IN ('PAID_OUT', 'COMPLETED') THEN 1 END) as depositos_pagos,
                    COUNT(CASE WHEN tipo = 'saque' AND status IN ('PAID_OUT', 'COMPLETED') THEN 1 END) as saques_pagos,
                    COUNT(CASE WHEN tipo = 'deposito' THEN 1 END) as depositos_gerados,
                    COUNT(CASE WHEN tipo = 'saque' THEN 1 END) as saques_gerados,
                    SUM(CASE WHEN tipo = 'deposito' AND status IN ('PAID_OUT', 'COMPLETED') THEN taxa ELSE 0 END) as tarifa_cobrada,
                    AVG(CASE WHEN tipo = 'deposito' AND status IN ('PAID_OUT', 'COMPLETED') THEN amount END) as ticket_medio_depositos,
                    AVG(CASE WHEN tipo = 'saque' AND status IN ('PAID_OUT', 'COMPLETED') THEN amount END) as ticket_medio_saques,
                    MIN(CASE WHEN tipo = 'deposito' AND status IN ('PAID_OUT', 'COMPLETED') THEN amount END) as valor_min_depositos,
                    MAX(CASE WHEN tipo = 'deposito' AND status IN ('PAID_OUT', 'COMPLETED') THEN amount END) as valor_max_depositos,
                    COUNT(CASE WHEN status IN ('MEDIATION', 'CHARGEBACK', 'DISPUTE') THEN 1 END) as infracoes,
                    SUM(CASE WHEN status IN ('MEDIATION', 'CHARGEBACK', 'DISPUTE') THEN amount ELSE 0 END) as valor_infracoes
                FROM (
                    SELECT 'deposito' as tipo, amount, status, taxa_cash_in as taxa 
                    FROM solicitacoes 
                    WHERE user_id = ? AND date BETWEEN ? AND ?
                    UNION ALL
                    SELECT 'saque' as tipo, amount, status, taxa_cash_out as taxa 
                    FROM solicitacoes_cash_out 
                    WHERE user_id = ? AND date BETWEEN ? AND ?
                ) as combined_data
            ";

            $result = DB::selectOne($summaryQuery, [
                $username, $dates['inicio'], $dates['fim'],
                $username, $dates['inicio'], $dates['fim']
            ]);

            $depositosGerados = $result->depositos_gerados ?? 0;
            $depositosPagos = $result->depositos_pagos ?? 0;
            $indiceConversao = $depositosGerados > 0 ? ($depositosPagos / $depositosGerados) * 100 : 0;
            $percentualInfracoes = $depositosPagos > 0 ? (($result->infracoes ?? 0) / $depositosPagos) * 100 : 0;

            return [
                'periodo' => $periodo,
                'data_inicio' => $dates['inicio']->format('Y-m-d H:i:s'),
                'data_fim' => $dates['fim']->format('Y-m-d H:i:s'),
                'quantidadeTransacoes' => [
                    'depositos' => (int) $depositosPagos,
                    'saques' => (int) ($result->saques_pagos ?? 0),
                ],
                'tarifaCobrada' => (float) ($result->tarifa_cobrada ?? 0),
                'qrCodes' => [
                    'pagos' => (int) $depositosPagos,
                    'gerados' => (int) $depositosGerados,
                ],
                'indiceConversao' => (float) $indiceConversao,
                'ticketMedio' => [
                    'depositos' => (float) ($result->ticket_medio_depositos ?? 0),
                    'saques' => (float) ($result->ticket_medio_saques ?? 0),
                ],
                'valorMinMax' => [
                    'depositos' => [
                        'min' => (float) ($result->valor_min_depositos ?? 0),
                        'max' => (float) ($result->valor_max_depositos ?? 0),
                    ],
                ],
                'infracoes' => (int) ($result->infracoes ?? 0),
                'percentualInfracoes' => [
                    'percentual' => (float) $percentualInfracoes,
                    'valorTotal' => (float) ($result->valor_infracoes ?? 0),
                ],
            ];
        });
    }

    /**
     * Obter dados para movimentação interativa (gráfico + cards)
     * 
     * @param string $username
     * @param string $periodo
     * @return array
     */
    public function getInteractiveMovement(string $username, string $periodo): array
    {
        $dates = $this->calculateDateRange($periodo);
        
        $cacheKey = sprintf('dashboard:interactive:%s:%s:%s:%s', 
            $username, $periodo, 
            $dates['inicio']->format('YmdHis'), 
            $dates['fim']->format('YmdHis')
        );
        
        return Cache::remember($cacheKey, self::CACHE_TTL_DASHBOARD, function () use ($username, $dates, $periodo) {
            // Query otimizada para cards
            $cardsQuery = "
                SELECT 
                    'deposito' as tipo,
                    SUM(CASE WHEN status IN ('PAID_OUT', 'COMPLETED') THEN amount ELSE 0 END) as total,
                    COUNT(CASE WHEN status IN ('PAID_OUT', 'COMPLETED') THEN 1 END) as qtd
                FROM solicitacoes 
                WHERE user_id = ? AND date BETWEEN ? AND ?
                
                UNION ALL
                
                SELECT 
                    'saque' as tipo,
                    SUM(CASE WHEN status IN ('PAID_OUT', 'COMPLETED') THEN amount ELSE 0 END) as total,
                    COUNT(CASE WHEN status IN ('PAID_OUT', 'COMPLETED') THEN 1 END) as qtd
                FROM solicitacoes_cash_out 
                WHERE user_id = ? AND date BETWEEN ? AND ?
            ";

            $cardResults = DB::select($cardsQuery, [
                $username, $dates['inicio'], $dates['fim'],
                $username, $dates['inicio'], $dates['fim']
            ]);

            $depositos = $cardResults[0] ?? (object)['total' => 0, 'qtd' => 0];
            $saques = $cardResults[1] ?? (object)['total' => 0, 'qtd' => 0];

            // Dados do gráfico
            $chartData = $this->getChartData($username, $dates, $periodo);

            return [
                'periodo' => $periodo,
                'data_inicio' => $dates['inicio']->format('Y-m-d H:i:s'),
                'data_fim' => $dates['fim']->format('Y-m-d H:i:s'),
                'cards' => [
                    'total_depositos' => (float) $depositos->total,
                    'qtd_depositos' => (int) $depositos->qtd,
                    'total_saques' => (float) $saques->total,
                    'qtd_saques' => (int) $saques->qtd,
                ],
                'chart' => $chartData,
            ];
        });
    }

    /**
     * Obter dados do gráfico otimizado
     * 
     * @param string $username
     * @param array $dates
     * @param string $periodo
     * @return array
     */
    private function getChartData(string $username, array $dates, string $periodo): array
    {
        $interval = $periodo === 'hoje' ? 'hour' : 'day';
        $format = $interval === 'hour' ? '%Y-%m-%d %H:00:00' : '%Y-%m-%d';
        
        // Query corrigida para MySQL strict mode - agrupar por período formatado
        $dateFormat = $interval === 'hour' ? '%Y-%m-%d %H:00:00' : '%Y-%m-%d';
        
        // Buscar depósitos agrupados por período
        $depositosQuery = DB::table('solicitacoes')
            ->select(DB::raw("DATE_FORMAT(date, '{$dateFormat}') as periodo"))
            ->selectRaw("SUM(CASE WHEN status IN ('PAID_OUT', 'COMPLETED') THEN amount ELSE 0 END) as depositos")
            ->where('user_id', $username)
            ->whereBetween('date', [$dates['inicio'], $dates['fim']])
            ->groupBy(DB::raw("DATE_FORMAT(date, '{$dateFormat}')"));

        // Buscar saques agrupados por período
        $saquesQuery = DB::table('solicitacoes_cash_out')
            ->select(DB::raw("DATE_FORMAT(date, '{$dateFormat}') as periodo"))
            ->selectRaw("SUM(CASE WHEN status IN ('PAID_OUT', 'COMPLETED') THEN amount ELSE 0 END) as saques")
            ->where('user_id', $username)
            ->whereBetween('date', [$dates['inicio'], $dates['fim']])
            ->groupBy(DB::raw("DATE_FORMAT(date, '{$dateFormat}')"));

        // Combinar resultados usando Collection
        $depositos = $depositosQuery->get()->keyBy('periodo');
        $saques = $saquesQuery->get()->keyBy('periodo');

        // Combinar períodos únicos
        $allPeriodos = $depositos->keys()->merge($saques->keys())->unique()->sort();

        $results = $allPeriodos->map(function ($periodo) use ($depositos, $saques) {
            return (object) [
                'periodo' => $periodo,
                'depositos' => (float) ($depositos->get($periodo)->depositos ?? 0),
                'saques' => (float) ($saques->get($periodo)->saques ?? 0),
            ];
        })->values()->all();

        return array_map(function ($row) {
            return [
                'periodo' => $row->periodo,
                'depositos' => (float) $row->depositos,
                'saques' => (float) $row->saques,
            ];
        }, $results);
    }
}














