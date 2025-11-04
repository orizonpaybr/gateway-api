<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{User, Solicitacoes, SolicitacoesCashOut};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Log};
use Carbon\Carbon;

/**
 * Controller para Dashboard Administrativo
 * 
 * Implementa boas práticas:
 * - Cache Redis para performance
 * - Query optimization com índices
 * - Service Layer Pattern
 * - DRY (Don't Repeat Yourself)
 * - Clean Code
 * - Escalabilidade e Manutenibilidade
 */
class AdminDashboardController extends Controller
{
    // Constantes para TTL de cache
    private const CACHE_TTL_DASHBOARD = 120; // 2 minutos
    private const CACHE_TTL_USERS = 300; // 5 minutos
    
    /**
     * Obter estatísticas completas do dashboard administrativo
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDashboardStats(Request $request)
    {
        try {
            // Verificar se usuário é admin
            $user = $request->user() ?? $request->user_auth;
            if (!$user || $user->permission != 3) {
                return $this->errorResponse('Acesso negado', 403);
            }

            // Obter período do request (hoje, ontem, 7dias, 30dias, tudo)
            $periodo = $request->input('periodo', 'hoje');
            [$dataInicio, $dataFim] = $this->getPeriodoDate($periodo);

            // Cache key baseado no período
            $cacheKey = "admin_dashboard_stats_{$periodo}_{$dataInicio->format('Y-m-d')}_{$dataFim->format('Y-m-d')}";
            
            // Usar cache Redis para otimização
            $stats = Cache::remember($cacheKey, self::CACHE_TTL_DASHBOARD, function () use ($dataInicio, $dataFim) {
                return $this->calculateDashboardStats($dataInicio, $dataFim);
            });

            return $this->successResponse($stats);

        } catch (\Exception $e) {
            Log::error('Erro ao obter stats do dashboard admin', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->errorResponse('Erro ao obter estatísticas', 500);
        }
    }

    /**
     * Obter lista de usuários com filtros e paginação
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUsers(Request $request)
    {
        try {
            // Verificar se usuário é admin
            $user = $request->user() ?? $request->user_auth;
            if (!$user || $user->permission != 3) {
                return $this->errorResponse('Acesso negado', 403);
            }

            $status = $request->input('status'); // null, 1 (aprovado), 5 (pendente)
            $search = $request->input('search');
            $perPage = $request->input('per_page', 20);
            $orderBy = $request->input('order_by', 'created_at');
            $orderDirection = $request->input('order_direction', 'desc');

            $query = User::query();

            // Filtros
            if ($status !== null) {
                $query->where('status', $status);
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('username', 'like', "%{$search}%")
                      ->orWhere('cpf_cnpj', 'like', "%{$search}%");
                });
            }

            // Ordenação
            $query->orderBy($orderBy, $orderDirection);

            // Paginação
            $users = $query->paginate($perPage);

            // Adicionar informações extras
            $data = $users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'user_id' => $user->user_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'cpf_cnpj' => $user->cpf_cnpj,
                    'status' => $user->status,
                    'saldo' => $user->saldo,
                    'created_at' => $user->created_at,
                    'total_transacoes' => $user->total_transacoes,
                    'transacoes_aproved' => $user->transacoes_aproved,
                    'transacoes_recused' => $user->transacoes_recused,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                    'last_page' => $users->lastPage(),
                ]
            ])->header('Access-Control-Allow-Origin', '*');

        } catch (\Exception $e) {
            Log::error('Erro ao obter usuários', [
                'error' => $e->getMessage()
            ]);
            
            return $this->errorResponse('Erro ao obter usuários', 500);
        }
    }

    /**
     * Obter transações recentes com filtros
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRecentTransactions(Request $request)
    {
        try {
            // Verificar se usuário é admin
            $user = $request->user() ?? $request->user_auth;
            if (!$user || $user->permission != 3) {
                return $this->errorResponse('Acesso negado', 403);
            }

            $type = $request->input('type'); // 'deposit', 'withdraw', null (ambos)
            $status = $request->input('status');
            $limit = $request->input('limit', 50);

            $transactions = [];

            // Buscar depósitos
            if (!$type || $type === 'deposit') {
                $deposits = Solicitacoes::with('user:id,user_id,name,username')
                    ->when($status, fn($q) => $q->where('status', $status))
                    ->orderBy('created_at', 'desc')
                    ->limit($limit)
                    ->get()
                    ->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'type' => 'deposit',
                            'user' => $item->user ? [
                                'id' => $item->user->id,
                                'name' => $item->user->name,
                                'username' => $item->user->username,
                            ] : null,
                            'amount' => $item->amount,
                            'taxa' => $item->taxa_cash_in,
                            'status' => $item->status,
                            'date' => $item->date,
                            'created_at' => $item->created_at,
                        ];
                    });

                $transactions = array_merge($transactions, $deposits->toArray());
            }

            // Buscar saques
            if (!$type || $type === 'withdraw') {
                $withdraws = SolicitacoesCashOut::with('user:id,user_id,name,username')
                    ->when($status, fn($q) => $q->where('status', $status))
                    ->orderBy('created_at', 'desc')
                    ->limit($limit)
                    ->get()
                    ->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'type' => 'withdraw',
                            'user' => $item->user ? [
                                'id' => $item->user->id,
                                'name' => $item->user->name,
                                'username' => $item->user->username,
                            ] : null,
                            'amount' => $item->amount,
                            'taxa' => $item->taxa_cash_out,
                            'status' => $item->status,
                            'date' => $item->date,
                            'created_at' => $item->created_at,
                        ];
                    });

                $transactions = array_merge($transactions, $withdraws->toArray());
            }

            // Ordenar por data
            usort($transactions, function ($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });

            // Limitar resultado
            $transactions = array_slice($transactions, 0, $limit);

            return $this->successResponse(['transactions' => $transactions]);

        } catch (\Exception $e) {
            Log::error('Erro ao obter transações recentes', [
                'error' => $e->getMessage()
            ]);
            
            return $this->errorResponse('Erro ao obter transações', 500);
        }
    }

    /**
     * Calcular estatísticas do dashboard
     * 
     * @param Carbon $dataInicio
     * @param Carbon $dataFim
     * @return array
     */
    private function calculateDashboardStats(Carbon $dataInicio, Carbon $dataFim): array
    {
        // Usar queries otimizadas com índices
        $solicitacoes = Solicitacoes::where('status', 'PAID_OUT')
            ->whereBetween('date', [$dataInicio, $dataFim]);

        $saques = SolicitacoesCashOut::where('status', 'COMPLETED')
            ->whereBetween('date', [$dataInicio, $dataFim]);

        // Calcular lucros com uma única query usando aggregates
        $depositStats = (clone $solicitacoes)
            ->select(
                DB::raw('SUM(taxa_cash_in) as lucro_depositos'),
                DB::raw('SUM(amount) as valor_total_depositos'),
                DB::raw('COUNT(*) as total_depositos')
            )
            ->first();

        $withdrawStats = (clone $saques)
            ->select(
                DB::raw('SUM(taxa_cash_out) as lucro_saques'),
                DB::raw('SUM(amount) as valor_total_saques'),
                DB::raw('COUNT(*) as total_saques')
            )
            ->first();

        $lucroDepositos = $depositStats->lucro_depositos ?? 0;
        $lucroSaques = $withdrawStats->lucro_saques ?? 0;
        $valorTotalDepositos = $depositStats->valor_total_depositos ?? 0;
        $valorTotalSaques = $withdrawStats->valor_total_saques ?? 0;
        $totalDepositos = $depositStats->total_depositos ?? 0;
        $totalSaques = $withdrawStats->total_saques ?? 0;

        // Calcular taxas pagas aos adquirentes
        $taxasAdquirentes = $this->calculateAcquirerFees($solicitacoes, $saques);
        
        // Lucro líquido (lucro - taxas de adquirentes)
        $lucroLiquido = ($lucroDepositos + $lucroSaques) - ($taxasAdquirentes['entradas'] + $taxasAdquirentes['saidas']);

        // Estatísticas de usuários
        $usuariosCadastrados = User::whereBetween('created_at', [$dataInicio, $dataFim])->count();
        $usuariosPendentes = User::where('status', 5)
            ->whereBetween('created_at', [$dataInicio, $dataFim])
            ->count();
        $usuariosAprovados = User::where('status', 1)
            ->whereBetween('created_at', [$dataInicio, $dataFim])
            ->count();

        // Saques pendentes
        $saquesPendentes = SolicitacoesCashOut::where('status', 'PENDING')
            ->whereBetween('date', [$dataInicio, $dataFim])
            ->select(
                DB::raw('SUM(amount) as valor_total'),
                DB::raw('COUNT(*) as quantidade')
            )
            ->first();

        // Saldo total em carteiras (cache por 5 minutos)
        $saldoTotalCarteiras = Cache::remember('total_wallets_balance', 300, function () {
            return User::sum('saldo');
        });

        return [
            'periodo' => [
                'inicio' => $dataInicio->format('Y-m-d H:i:s'),
                'fim' => $dataFim->format('Y-m-d H:i:s'),
            ],
            'financeiro' => [
                'saldo_carteiras' => (float) $saldoTotalCarteiras,
                'lucro_liquido' => (float) $lucroLiquido,
                'lucro_depositos' => (float) $lucroDepositos,
                'lucro_saques' => (float) $lucroSaques,
                'taxas_adquirentes' => [
                    'entradas' => (float) $taxasAdquirentes['entradas'],
                    'saidas' => (float) $taxasAdquirentes['saidas'],
                    'total' => (float) ($taxasAdquirentes['entradas'] + $taxasAdquirentes['saidas']),
                ],
            ],
            'transacoes' => [
                'depositos' => [
                    'quantidade' => (int) $totalDepositos,
                    'valor_total' => (float) $valorTotalDepositos,
                ],
                'saques' => [
                    'quantidade' => (int) $totalSaques,
                    'valor_total' => (float) $valorTotalSaques,
                ],
                'total' => [
                    'quantidade' => (int) ($totalDepositos + $totalSaques),
                    'valor_total' => (float) ($valorTotalDepositos + $valorTotalSaques),
                ],
            ],
            'usuarios' => [
                'cadastrados' => (int) $usuariosCadastrados,
                'pendentes' => (int) $usuariosPendentes,
                'aprovados' => (int) $usuariosAprovados,
            ],
            'saques_pendentes' => [
                'quantidade' => (int) ($saquesPendentes->quantidade ?? 0),
                'valor_total' => (float) ($saquesPendentes->valor_total ?? 0),
            ],
        ];
    }

    /**
     * Calcular taxas pagas aos adquirentes
     * 
     * @param $solicitacoes
     * @param $saques
     * @return array
     */
    private function calculateAcquirerFees($solicitacoes, $saques): array
    {
        $taxasAdquirentesEntradas = 0;
        $taxasAdquirentesSaidas = 0;
        
        // Buscar taxa da XDPag (adquirente padrão)
        // Implementar cache para não buscar sempre
        $xdpag = Cache::remember('xdpag_config', 3600, function () {
            return \App\Models\XDPag::first();
        });

        if ($xdpag) {
            $taxasAdquirentesEntradas = (clone $solicitacoes)
                ->sum(DB::raw('amount * ' . ($xdpag->taxa_adquirente_entradas / 100)));
            
            $taxasAdquirentesSaidas = (clone $saques)
                ->sum(DB::raw('amount * ' . ($xdpag->taxa_adquirente_saidas / 100)));
        }

        return [
            'entradas' => $taxasAdquirentesEntradas,
            'saidas' => $taxasAdquirentesSaidas,
        ];
    }

    /**
     * Obter datas do período
     * 
     * @param string $periodo
     * @return array [Carbon, Carbon]
     */
    private function getPeriodoDate(string $periodo): array
    {
        $dataInicio = null;
        $dataFim = null;

        switch ($periodo) {
            case 'hoje':
                $dataInicio = Carbon::today()->startOfDay();
                $dataFim = Carbon::today()->endOfDay();
                break;

            case 'ontem':
                $dataInicio = Carbon::yesterday()->startOfDay();
                $dataFim = Carbon::yesterday()->endOfDay();
                break;

            case '7dias':
                $dataInicio = Carbon::today()->subDays(6)->startOfDay();
                $dataFim = Carbon::today()->endOfDay();
                break;

            case '30dias':
                $dataInicio = Carbon::today()->subDays(29)->startOfDay();
                $dataFim = Carbon::today()->endOfDay();
                break;

            case 'mes_atual':
                $dataInicio = Carbon::now()->startOfMonth();
                $dataFim = Carbon::now()->endOfMonth();
                break;

            case 'mes_anterior':
                $dataInicio = Carbon::now()->subMonth()->startOfMonth();
                $dataFim = Carbon::now()->subMonth()->endOfMonth();
                break;

            case 'tudo':
                $dataInicio = Carbon::today()->subYears(100)->startOfDay();
                $dataFim = Carbon::today()->endOfDay();
                break;

            default:
                // Formato customizado: "YYYY-MM-DD:YYYY-MM-DD"
                if (str_contains($periodo, ':')) {
                    [$start, $end] = explode(':', $periodo);
                    $dataInicio = Carbon::parse($start)->startOfDay();
                    $dataFim = Carbon::parse($end)->endOfDay();
                } else {
                    $dataInicio = Carbon::today()->startOfDay();
                    $dataFim = Carbon::today()->endOfDay();
                }
                break;
        }

        return [$dataInicio, $dataFim];
    }

    /**
     * Resposta de sucesso padronizada
     * 
     * @param array $data
     * @return \Illuminate\Http\JsonResponse
     */
    private function successResponse(array $data)
    {
        return response()->json([
            'success' => true,
            'data' => $data
        ])->header('Access-Control-Allow-Origin', '*');
    }

    /**
     * Resposta de erro padronizada
     * 
     * @param string $message
     * @param int $code
     * @return \Illuminate\Http\JsonResponse
     */
    private function errorResponse(string $message, int $code = 400)
    {
        return response()->json([
            'success' => false,
            'message' => $message
        ], $code)->header('Access-Control-Allow-Origin', '*');
    }
}

