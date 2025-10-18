<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class OptimizedDashboardController extends Controller
{
    /**
     * Estatísticas do dashboard com query otimizada
     */
    public function getDashboardStats(Request $request)
    {
        try {
            $user = $request->user() ?? ($request->user_auth ?? null);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $cacheKey = sprintf('dashboard:stats:%s:%s', $user->username, now()->format('Y-m-d'));
            $cacheTtl = 300; // 5 minutos

            $payload = Cache::remember($cacheKey, $cacheTtl, function () use ($user) {
                $startOfMonth = now()->startOfMonth();
                $endOfMonth = now()->endOfMonth();

                // Query otimizada com uma única consulta usando UNION ALL
                $statsQuery = "
                    SELECT 
                        'deposito' as tipo,
                        SUM(CASE WHEN status IN ('PAID_OUT', 'COMPLETED') THEN amount ELSE 0 END) as total_pago,
                        SUM(CASE WHEN status IN ('PAID_OUT', 'COMPLETED') THEN 1 ELSE 0 END) as qtd_pago,
                        COUNT(*) as total_gerado,
                        SUM(CASE WHEN status IN ('PAID_OUT', 'COMPLETED') THEN taxa_cash_in ELSE 0 END) as total_taxa
                    FROM solicitacoes 
                    WHERE user_id = ? AND date BETWEEN ? AND ?
                    
                    UNION ALL
                    
                    SELECT 
                        'saque' as tipo,
                        SUM(CASE WHEN status IN ('PAID_OUT', 'COMPLETED') THEN amount ELSE 0 END) as total_pago,
                        SUM(CASE WHEN status IN ('PAID_OUT', 'COMPLETED') THEN 1 ELSE 0 END) as qtd_pago,
                        COUNT(*) as total_gerado,
                        SUM(CASE WHEN status IN ('PAID_OUT', 'COMPLETED') THEN taxa_cash_out ELSE 0 END) as total_taxa
                    FROM solicitacoes_cash_out 
                    WHERE user_id = ? AND date BETWEEN ? AND ?
                ";

                $results = DB::select($statsQuery, [
                    $user->username, $startOfMonth, $endOfMonth,
                    $user->username, $startOfMonth, $endOfMonth
                ]);

                $depositos = $results[0] ?? (object)['total_pago' => 0, 'qtd_pago' => 0, 'total_gerado' => 0, 'total_taxa' => 0];
                $saques = $results[1] ?? (object)['total_pago' => 0, 'qtd_pago' => 0, 'total_gerado' => 0, 'total_taxa' => 0];

                // Calcular saldo disponível (otimizado)
                $saldoQuery = "
                    SELECT COALESCE(SUM(valor_split), 0) as saldo_disponivel
                    FROM solicitacoes 
                    WHERE user_id = ? AND status = 'processado'
                ";
                $saldoResult = DB::selectOne($saldoQuery, [$user->username]);
                $saldoDisponivel = $saldoResult->saldo_disponivel ?? 0;

                return [
                    'saldo_disponivel' => (float) $saldoDisponivel,
                    'entradas_mes' => (float) $depositos->total_pago,
                    'saidas_mes' => (float) $saques->total_pago,
                    'splits_mes' => (float) ($depositos->total_taxa + $saques->total_taxa),
                    'periodo' => [
                        'inicio' => $startOfMonth->format('Y-m-d'),
                        'fim' => $endOfMonth->format('Y-m-d'),
                    ],
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $payload
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao obter estatísticas do dashboard', [
                'error' => $e->getMessage(),
                'user_id' => $user->username ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Movimentação interativa com query otimizada
     */
    public function getInteractiveMovement(Request $request)
    {
        try {
            $user = $request->user() ?? ($request->user_auth ?? null);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $periodo = $request->input('periodo', 'hoje');
            $dates = $this->calculateInteractiveDateRange($periodo);
            
            $cacheKey = sprintf('dashboard:interactive:%s:%s:%s:%s', 
                $user->username, $periodo, 
                $dates['inicio']->format('YmdHis'), 
                $dates['fim']->format('YmdHis')
            );
            $cacheTtl = 60; // 1 minuto

            $payload = Cache::remember($cacheKey, $cacheTtl, function () use ($user, $dates, $periodo) {
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
                    $user->username, $dates['inicio'], $dates['fim'],
                    $user->username, $dates['inicio'], $dates['fim']
                ]);

                $depositos = $cardResults[0] ?? (object)['total' => 0, 'qtd' => 0];
                $saques = $cardResults[1] ?? (object)['total' => 0, 'qtd' => 0];

                // Query otimizada para gráfico
                $chartData = $this->getChartDataOptimized($user->username, $dates, $periodo);

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

            return response()->json([
                'success' => true,
                'data' => $payload
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao obter movimentação interativa', [
                'error' => $e->getMessage(),
                'user_id' => $user->username ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Resumo de transações com query otimizada
     */
    public function getTransactionSummary(Request $request)
    {
        try {
            $user = $request->user() ?? ($request->user_auth ?? null);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado'
                ], 401)->header('Access-Control-Allow-Origin', '*');
            }

            $periodo = $request->input('periodo', 'hoje');
            $dates = $this->calculateInteractiveDateRange($periodo);
            
            $cacheKey = sprintf('dashboard:summary:%s:%s:%s:%s', 
                $user->username, $periodo, 
                $dates['inicio']->format('YmdHis'), 
                $dates['fim']->format('YmdHis')
            );
            $cacheTtl = 60; // 1 minuto

            $payload = Cache::remember($cacheKey, $cacheTtl, function () use ($user, $dates, $periodo) {
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
                        SELECT 'deposito' as tipo, amount, status, taxa_cash_in as taxa FROM solicitacoes WHERE user_id = ? AND date BETWEEN ? AND ?
                        UNION ALL
                        SELECT 'saque' as tipo, amount, status, taxa_cash_out as taxa FROM solicitacoes_cash_out WHERE user_id = ? AND date BETWEEN ? AND ?
                    ) as combined_data
                ";

                $result = DB::selectOne($summaryQuery, [
                    $user->username, $dates['inicio'], $dates['fim'],
                    $user->username, $dates['inicio'], $dates['fim']
                ]);

                $depositosGerados = $result->depositos_gerados ?? 0;
                $depositosPagos = $result->depositos_pagos ?? 0;
                $indiceConversao = $depositosGerados > 0 ? ($depositosPagos / $depositosGerados) * 100 : 0;

                return [
                    'periodo' => $periodo,
                    'data_inicio' => $dates['inicio']->format('Y-m-d H:i:s'),
                    'data_fim' => $dates['fim']->format('Y-m-d H:i:s'),
                    'quantidadeTransacoes' => [
                        'depositos' => (int) $depositosPagos,
                        'saques' => (int) $result->saques_pagos,
                    ],
                    'tarifaCobrada' => (float) $result->tarifa_cobrada,
                    'qrCodes' => [
                        'pagos' => (int) $depositosPagos,
                        'gerados' => (int) $depositosGerados,
                    ],
                    'indiceConversao' => (float) $indiceConversao,
                    'ticketMedio' => [
                        'depositos' => (float) $result->ticket_medio_depositos,
                        'saques' => (float) $result->ticket_medio_saques,
                    ],
                    'valorMinMax' => [
                        'depositos' => [
                            'min' => (float) $result->valor_min_depositos,
                            'max' => (float) $result->valor_max_depositos,
                        ],
                    ],
                    'infracoes' => (int) $result->infracoes,
                    'percentualInfracoes' => [
                        'percentual' => $depositosPagos > 0 ? (($result->infracoes / $depositosPagos) * 100) : 0,
                        'valorTotal' => (float) $result->valor_infracoes,
                    ],
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $payload
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao obter resumo de transações', [
                'error' => $e->getMessage(),
                'user_id' => $user->username ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor'
            ], 500)->header('Access-Control-Allow-Origin', '*');
        }
    }

    /**
     * Calcular intervalo de datas para movimentação interativa
     */
    private function calculateInteractiveDateRange($periodo)
    {
        $now = Carbon::now('America/Sao_Paulo');
        
        switch ($periodo) {
            case 'hoje':
                return [
                    'inicio' => $now->copy()->startOfDay(),
                    'fim' => $now->copy()->endOfDay()
                ];
                
            case 'ontem':
                return [
                    'inicio' => $now->copy()->subDay()->startOfDay(),
                    'fim' => $now->copy()->subDay()->endOfDay()
                ];
                
            case '7dias':
                return [
                    'inicio' => $now->copy()->subDays(6)->startOfDay(),
                    'fim' => $now->copy()->endOfDay()
                ];
                
            case '30dias':
                return [
                    'inicio' => $now->copy()->subDays(29)->startOfDay(),
                    'fim' => $now->copy()->endOfDay()
                ];
                
            default:
                return [
                    'inicio' => $now->copy()->startOfDay(),
                    'fim' => $now->copy()->endOfDay()
                ];
        }
    }

    /**
     * Dados do gráfico otimizados
     */
    private function getChartDataOptimized($username, $dates, $periodo)
    {
        $interval = $periodo === 'hoje' ? 'hour' : 'day';
        
        $query = "
            SELECT 
                DATE_FORMAT(date, ?) as periodo,
                SUM(CASE WHEN tipo = 'deposito' AND status IN ('PAID_OUT', 'COMPLETED') THEN amount ELSE 0 END) as depositos,
                SUM(CASE WHEN tipo = 'saque' AND status IN ('PAID_OUT', 'COMPLETED') THEN amount ELSE 0 END) as saques
            FROM (
                SELECT date, amount, status, 'deposito' as tipo FROM solicitacoes WHERE user_id = ? AND date BETWEEN ? AND ?
                UNION ALL
                SELECT date, amount, status, 'saque' as tipo FROM solicitacoes_cash_out WHERE user_id = ? AND date BETWEEN ? AND ?
            ) as combined_data
            GROUP BY DATE_FORMAT(date, ?)
            ORDER BY periodo
        ";

        $format = $interval === 'hour' ? '%Y-%m-%d %H:00:00' : '%Y-%m-%d';
        
        $results = DB::select($query, [
            $format, $username, $dates['inicio'], $dates['fim'],
            $username, $dates['inicio'], $dates['fim'], $format
        ]);

        return array_map(function ($row) {
            return [
                'periodo' => $row->periodo,
                'depositos' => (float) $row->depositos,
                'saques' => (float) $row->saques,
            ];
        }, $results);
    }
}
