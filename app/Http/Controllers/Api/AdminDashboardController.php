<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{User, Solicitacoes, SolicitacoesCashOut};
use App\Services\{AdminUserService, CacheKeyService};
use App\Models\UsersKey;
use App\Models\Adquirente;
use App\Http\Requests\Admin\{StoreUserRequest, UpdateUserRequest, AffiliateSettingsRequest};
use App\Constants\{UserStatus, UserPermission, AffiliateSettings};
use App\Helpers\{UserStatusHelper, AppSettingsHelper};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Log, Redis};
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
     * Service para gerenciamento de usuários
     */
    protected AdminUserService $userService;
    
    /**
     * Constructor
     */
    public function __construct(AdminUserService $userService)
    {
        $this->userService = $userService;
    }
    
    /**
     * Obter estatísticas completas do dashboard administrativo
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDashboardStats(Request $request)
    {
        try {
            // Verificação de admin feita pelo middleware 'ensure.admin'
            // Obter período do request (hoje, ontem, 7dias, 30dias, tudo)
            $periodo = $request->input('periodo', 'hoje');
            [$dataInicio, $dataFim] = $this->getPeriodoDate($periodo);

            // Cache key baseado no período usando CacheKeyService
            $cacheKey = CacheKeyService::adminDashboardStats($periodo, $dataInicio, $dataFim);
            
            // Usar Redis explicitamente para otimização (seguindo padrão do projeto)
            try {
                $cached = Redis::get($cacheKey);
                if ($cached) {
                    return $this->successResponse(json_decode($cached, true));
                }
            } catch (\Exception $e) {
                Log::warning('Erro ao ler cache Redis, usando fallback', ['error' => $e->getMessage()]);
            }
            
            // Se não estiver no cache, calcular e armazenar
            $stats = $this->calculateDashboardStats($dataInicio, $dataFim);
            
            try {
                Redis::setex($cacheKey, self::CACHE_TTL_DASHBOARD, json_encode($stats));
            } catch (\Exception $e) {
                Log::warning('Erro ao escrever cache Redis, continuando sem cache', ['error' => $e->getMessage()]);
            }

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
            // Verificação de admin feita pelo middleware 'ensure.admin'
            $status = $request->input('status');
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

            // CORREÇÃO N+1: Buscar todas as vendas de uma vez
            $userIds = $users->pluck('user_id');
            $vendas7d = Solicitacoes::whereIn('user_id', $userIds)
                ->where('status', 'PAID_OUT')
                ->where('date', '>=', now()->subDays(7))
                ->selectRaw('user_id, SUM(amount) as total')
                ->groupBy('user_id')
                ->pluck('total', 'user_id');

            // CORREÇÃO N+1: Buscar adquirentes de uma vez
            $adquirentesRefs = $users->pluck('preferred_adquirente')->filter()->unique();
            $adquirentes = Adquirente::whereIn('referencia', $adquirentesRefs)
                ->get()
                ->keyBy('referencia');

            // Mapear dados (sem queries dentro do loop)
            $data = $users->map(function ($user) use ($vendas7d, $adquirentes) {
                // Obter vendas 7d (sem query adicional)
                $vendas7dTotal = $vendas7d[$user->user_id] ?? 0;

                // Determinar status da documentação
                $docStatus = 'ANALISE';
                if ($user->foto_rg_frente && $user->foto_rg_verso && $user->selfie_rg) {
                    $docStatus = 'OK';
                }

                // Obter adquirente (sem query adicional)
                $adquirente = 'Padrão';
                if ($user->preferred_adquirente && $user->adquirente_override) {
                    $adq = $adquirentes[$user->preferred_adquirente] ?? null;
                    if ($adq) {
                        $adquirente = $adq->adquirente;
                    }
                }

                // Determinar texto de permissão usando constant
                $permissionText = UserPermission::getText($user->permission ?? UserPermission::CLIENT);

                // Determinar status_text usando helper (DRY)
                $statusTexto = UserStatusHelper::getStatusText($user);

                return [
                    'id' => $user->id,
                    'user_id' => $user->user_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'cpf_cnpj' => $user->cpf_cnpj,
                    'status' => $user->status,
                    'status_text' => $statusTexto,
                    'banido' => (bool) ($user->banido ?? false),
                    'aprovado_alguma_vez' => (bool) ($user->aprovado_alguma_vez ?? false),
                    'saldo' => $user->saldo,
                    'permission' => $user->permission ?? UserPermission::CLIENT,
                    'permission_text' => $permissionText,
                    'adquirente' => $adquirente,
                    'vendas_7d' => (float) $vendas7dTotal,
                    'doc_status' => $docStatus,
                    'created_at' => $user->created_at,
                    'total_transacoes' => $user->total_transacoes ?? 0,
                    'transacoes_aproved' => $user->transacoes_aproved ?? 0,
                    'transacoes_recused' => $user->transacoes_recused ?? 0,
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
     * Obter estatísticas de usuários para os cards
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserStats(Request $request)
    {
        try {
            // Verificação de admin feita pelo middleware 'ensure.admin'
            // Cache key para estatísticas de usuários usando CacheKeyService
            $cacheKey = CacheKeyService::adminUsersStats();
            
            // Usar Redis explicitamente para otimização (seguindo padrão do projeto)
            try {
                $cached = Redis::get($cacheKey);
                if ($cached) {
                    return $this->successResponse(json_decode($cached, true));
                }
            } catch (\Exception $e) {
                Log::warning('Erro ao ler cache Redis, usando fallback', ['error' => $e->getMessage()]);
            }
            
            // Se não estiver no cache, calcular e armazenar
            // Total de registros
            $totalRegistrations = User::count();
            
            // Registros do mês atual
            $monthRegistrations = User::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();
            
            // Pendentes (status PENDING usando constant)
            $pendingRegistrations = User::where('status', UserStatus::PENDING)->count();
            
            // Banidos (apenas banido = true, excluídos não contam como banidos)
            $bannedUsers = User::where('banido', true)->count();

            $stats = [
                'total_registrations' => $totalRegistrations,
                'month_registrations' => $monthRegistrations,
                'pending_registrations' => $pendingRegistrations,
                'banned_users' => $bannedUsers,
            ];
            
            try {
                Redis::setex($cacheKey, 300, json_encode($stats)); // 5 minutos
            } catch (\Exception $e) {
                Log::warning('Erro ao escrever cache Redis, continuando sem cache', ['error' => $e->getMessage()]);
            }

            return $this->successResponse($stats);

        } catch (\Exception $e) {
            Log::error('Erro ao obter estatísticas de usuários', [
                'error' => $e->getMessage()
            ]);
            
            return $this->errorResponse('Erro ao obter estatísticas de usuários', 500);
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
            // Verificação de admin feita pelo middleware 'ensure.admin'
            $type = $request->input('type'); // 'deposit', 'withdraw', null (ambos)
            $status = $request->input('status');
            $limit = $request->input('limit', 50);

            // CORREÇÃO: Buscar depósitos e saques separadamente, já ordenados no banco
            $deposits = collect();
            $withdraws = collect();

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
            }

            // CORREÇÃO: Mesclar e ordenar usando Collection (mais eficiente que usort)
            $transactions = $deposits
                ->merge($withdraws)
                ->sortByDesc('created_at')
                ->take($limit)
                ->values()
                ->toArray();

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
     * Refatorado em métodos menores para melhor manutenibilidade
     * 
     * @param Carbon $dataInicio
     * @param Carbon $dataFim
     * @return array
     */
    private function calculateDashboardStats(Carbon $dataInicio, Carbon $dataFim): array
    {
        return [
            'periodo' => $this->formatPeriod($dataInicio, $dataFim),
            'financeiro' => $this->calculateFinancialStats($dataInicio, $dataFim),
            'transacoes' => $this->calculateTransactionStats($dataInicio, $dataFim),
            'usuarios' => $this->calculateUserStats($dataInicio, $dataFim),
            'saques_pendentes' => $this->calculatePendingWithdrawals($dataInicio, $dataFim),
        ];
    }
    
    /**
     * Formatar período
     */
    private function formatPeriod(Carbon $dataInicio, Carbon $dataFim): array
    {
        return [
            'inicio' => $dataInicio->format('Y-m-d H:i:s'),
            'fim' => $dataFim->format('Y-m-d H:i:s'),
        ];
    }
    
    /**
     * Calcular estatísticas financeiras
     */
    private function calculateFinancialStats(Carbon $dataInicio, Carbon $dataFim): array
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

        // Calcular taxas pagas aos adquirentes
        $taxasAdquirentes = $this->calculateAcquirerFees($solicitacoes, $saques);
        
        // Lucro líquido (lucro - taxas de adquirentes)
        $lucroLiquido = ($lucroDepositos + $lucroSaques) - ($taxasAdquirentes['entradas'] + $taxasAdquirentes['saidas']);

        // Saldo total em carteiras (usando Redis explicitamente)
        $balanceCacheKey = CacheKeyService::totalWalletsBalance();
        try {
            $cached = Redis::get($balanceCacheKey);
            if ($cached !== null) {
                $saldoTotalCarteiras = (float) $cached;
            } else {
                $saldoTotalCarteiras = User::sum('saldo');
                Redis::setex($balanceCacheKey, 300, (string) $saldoTotalCarteiras); // 5 minutos
            }
        } catch (\Exception $e) {
            Log::warning('Erro ao ler/escrever cache Redis de saldo, usando cálculo direto', ['error' => $e->getMessage()]);
            $saldoTotalCarteiras = User::sum('saldo');
        }

        return [
            'saldo_carteiras' => (float) $saldoTotalCarteiras,
            'lucro_liquido' => (float) $lucroLiquido,
            'lucro_depositos' => (float) $lucroDepositos,
            'lucro_saques' => (float) $lucroSaques,
            'taxas_adquirentes' => [
                'entradas' => (float) $taxasAdquirentes['entradas'],
                'saidas' => (float) $taxasAdquirentes['saidas'],
                'total' => (float) ($taxasAdquirentes['entradas'] + $taxasAdquirentes['saidas']),
            ],
        ];
    }
    
    /**
     * Calcular estatísticas de transações
     */
    private function calculateTransactionStats(Carbon $dataInicio, Carbon $dataFim): array
    {
        $solicitacoes = Solicitacoes::where('status', 'PAID_OUT')
            ->whereBetween('date', [$dataInicio, $dataFim]);

        $saques = SolicitacoesCashOut::where('status', 'COMPLETED')
            ->whereBetween('date', [$dataInicio, $dataFim]);

        $depositStats = (clone $solicitacoes)
            ->select(
                DB::raw('SUM(amount) as valor_total_depositos'),
                DB::raw('COUNT(*) as total_depositos')
            )
            ->first();

        $withdrawStats = (clone $saques)
            ->select(
                DB::raw('SUM(amount) as valor_total_saques'),
                DB::raw('COUNT(*) as total_saques')
            )
            ->first();

        $totalDepositos = $depositStats->total_depositos ?? 0;
        $totalSaques = $withdrawStats->total_saques ?? 0;
        $valorTotalDepositos = $depositStats->valor_total_depositos ?? 0;
        $valorTotalSaques = $withdrawStats->valor_total_saques ?? 0;

        return [
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
        ];
    }
    
    /**
     * Calcular estatísticas de usuários
     */
    private function calculateUserStats(Carbon $dataInicio, Carbon $dataFim): array
    {
        $usuariosCadastrados = User::whereBetween('created_at', [$dataInicio, $dataFim])->count();
        $usuariosPendentes = User::where('status', UserStatus::PENDING)
            ->whereBetween('created_at', [$dataInicio, $dataFim])
            ->count();
        $usuariosAprovados = User::where('status', UserStatus::ACTIVE)
            ->whereBetween('created_at', [$dataInicio, $dataFim])
            ->count();

        return [
            'cadastrados' => (int) $usuariosCadastrados,
            'pendentes' => (int) $usuariosPendentes,
            'aprovados' => (int) $usuariosAprovados,
        ];
    }
    
    /**
     * Calcular saques pendentes
     */
    private function calculatePendingWithdrawals(Carbon $dataInicio, Carbon $dataFim): array
    {
        $saquesPendentes = SolicitacoesCashOut::where('status', 'PENDING')
            ->whereBetween('date', [$dataInicio, $dataFim])
            ->select(
                DB::raw('SUM(amount) as valor_total'),
                DB::raw('COUNT(*) as quantidade')
            )
            ->first();

        return [
            'quantidade' => (int) ($saquesPendentes->quantidade ?? 0),
            'valor_total' => (float) ($saquesPendentes->valor_total ?? 0),
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
        
        // Buscar taxa da XDPag (adquirente padrão) usando Redis explicitamente
        $xdpagCacheKey = CacheKeyService::xdpagConfig();
        try {
            $cached = Redis::get($xdpagCacheKey);
            if ($cached) {
                $xdpag = json_decode($cached, true);
                if ($xdpag) {
                    $xdpag = (object) $xdpag; // Converter para objeto se necessário
                } else {
                    $xdpag = \App\Models\XDPag::first();
                    Redis::setex($xdpagCacheKey, 3600, json_encode($xdpag)); // 1 hora
                }
            } else {
                $xdpag = \App\Models\XDPag::first();
                if ($xdpag) {
                    Redis::setex($xdpagCacheKey, 3600, json_encode($xdpag)); // 1 hora
                }
            }
        } catch (\Exception $e) {
            Log::warning('Erro ao ler/escrever cache Redis de XDPag, usando query direta', ['error' => $e->getMessage()]);
            $xdpag = \App\Models\XDPag::first();
        }

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
    
    // =====================================================
    // CRUD DE USUÁRIOS
    // =====================================================
    
    /**
     * Listar gerentes (permission = 2)
     */
    public function listManagers(Request $request)
    {
        try {
            // Verificação de admin feita pelo middleware 'ensure.admin'
            // MELHORIA: Adicionar paginação e busca
            $perPage = $request->input('per_page', 50);
            $search = $request->input('search');
            
            $query = User::where('permission', UserPermission::MANAGER)
                ->where('status', UserStatus::ACTIVE);
            
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }
            
            $managers = $query->orderBy('name')
                ->paginate($perPage, ['id', 'name', 'username', 'email']);
            
            return $this->successResponse([
                'managers' => $managers->items(),
                'pagination' => [
                    'current_page' => $managers->currentPage(),
                    'per_page' => $managers->perPage(),
                    'total' => $managers->total(),
                    'last_page' => $managers->lastPage(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao listar gerentes', ['error' => $e->getMessage()]);
            return $this->errorResponse('Erro ao listar gerentes', 500);
        }
    }
    
    /**
     * Listar adquirentes ativos para PIX
     */
    public function listPixAcquirers(Request $request)
    {
        try {
            // Verificação de admin feita pelo middleware 'ensure.admin'
            // MELHORIA: Adicionar paginação e busca
            $perPage = $request->input('per_page', 50);
            $search = $request->input('search');
            
            $query = Adquirente::where('status', 1);
            
            if ($search) {
                $query->where('adquirente', 'like', "%{$search}%")
                      ->orWhere('referencia', 'like', "%{$search}%");
            }
            
            $acquirers = $query->orderBy('adquirente')
                ->paginate($perPage, ['adquirente as name', 'referencia']);
            
            return $this->successResponse([
                'acquirers' => $acquirers->items(),
                'pagination' => [
                    'current_page' => $acquirers->currentPage(),
                    'per_page' => $acquirers->perPage(),
                    'total' => $acquirers->total(),
                    'last_page' => $acquirers->lastPage(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao listar adquirentes', ['error' => $e->getMessage()]);
            return $this->errorResponse('Erro ao listar adquirentes', 500);
        }
    }
    
    /**
     * Obter detalhes de um usuário específico
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function showUser(Request $request, int $id)
    {
        try {
            // Verificação de admin feita pelo middleware 'ensure.admin'
            $user = $this->userService->getUserById($id, true);

            if (!$user) {
                return $this->errorResponse('Usuário não encontrado', 404);
            }

            // Montar payload detalhado (sanitizado)
            $keys = UsersKey::where('user_id', $user->user_id)->first();

            // Determinar status_text usando helper (DRY)
            $statusTexto = UserStatusHelper::getStatusText($user);

            // Buscar taxas padrão do sistema (tabela app) com cache
            $setting = AppSettingsHelper::getSettings();
            
            // Determinar se usa taxas personalizadas ou globais
            $usandoPersonalizadas = $user->taxas_personalizadas_ativas ?? false;
            
            // IMPORTANTE: No contexto de edição (admin), sempre retornar os valores salvos no banco
            // O flag taxas_personalizadas_ativas só afeta qual valor é USADO no sistema,
            // mas no modal de edição devemos mostrar os valores salvos, mesmo que não estejam ativos
            // Se o valor não existir no banco, aí sim usar o padrão do sistema

            $detail = [
                'id' => $user->id,
                'user_id' => $user->user_id,
                'username' => $user->username,
                'name' => $user->name,
                'email' => $user->email,
                'permission' => (int) ($user->permission ?? UserPermission::CLIENT),
                'status' => (int) $user->status,
                'status_text' => $statusTexto,
                'banido' => (bool) ($user->banido ?? false),
                'aprovado_alguma_vez' => (bool) ($user->aprovado_alguma_vez ?? false),
                'created_at' => optional($user->created_at)->toDateTimeString(),
                // Identificação
                'cpf' => $user->cpf,
                'cpf_cnpj' => $user->cpf_cnpj,
                'data_nascimento' => $user->data_nascimento,
                'telefone' => $user->telefone,
                // Empresa
                'razao_social' => $user->razao_social,
                'nome_fantasia' => $user->nome_fantasia,
                // Endereço
                'cep' => $user->cep,
                'rua' => $user->rua,
                'bairro' => $user->bairro,
                'cidade' => $user->cidade,
                'estado' => $user->estado,
                'numero_residencia' => $user->numero_residencia,
                'complemento' => $user->complemento,
                // Financeiro
                'saldo' => (float) ($user->saldo ?? 0),
                // Integrações
                'token' => $keys->token ?? null,
                'secret' => $keys->secret ?? null,
                // Documentação (caminhos relativos - serão resolvidos pelo frontend)
                'documents' => [
                    'rg_frente' => $user->foto_rg_frente,
                    'rg_verso' => $user->foto_rg_verso,
                    'selfie_rg' => $user->selfie_rg,
                ],
                // Taxas - SEMPRE retornar valores salvos no banco (se existirem), senão usar padrão
                // Converter para float para garantir que retorne número e não string
                'taxas_personalizadas_ativas' => $usandoPersonalizadas,
                'taxa_percentual_deposito' => (float) ($user->taxa_percentual_deposito !== null 
                    ? $user->taxa_percentual_deposito 
                    : ($setting->taxa_cash_in_padrao ?? 4.00)),
                'taxa_fixa_deposito' => (float) ($user->taxa_fixa_deposito !== null 
                    ? $user->taxa_fixa_deposito 
                    : ($setting->taxa_fixa_padrao ?? 0.00)),
                'valor_minimo_deposito' => (float) ($user->valor_minimo_deposito !== null
                    ? $user->valor_minimo_deposito 
                    : ($setting->deposito_minimo ?? 1.00)),
                // Taxas - Saque
                'taxa_percentual_pix' => (float) ($user->taxa_percentual_pix !== null
                    ? $user->taxa_percentual_pix 
                    : ($setting->taxa_cash_out_padrao ?? 4.00)),
                'taxa_minima_pix' => (float) ($user->taxa_minima_pix !== null
                    ? $user->taxa_minima_pix 
                    : 0.80),
                'taxa_fixa_pix' => (float) ($user->taxa_fixa_pix !== null
                    ? $user->taxa_fixa_pix 
                    : ($setting->taxa_fixa_pix ?? 0.00)),
                'valor_minimo_saque' => (float) ($user->valor_minimo_saque !== null
                    ? $user->valor_minimo_saque 
                    : ($setting->saque_minimo ?? 1.00)),
                // Limites e extras
                'limite_mensal_pf' => (float) ($user->limite_mensal_pf !== null
                    ? $user->limite_mensal_pf 
                    : ($setting->limite_saque_mensal ?? 50000.00)),
                'taxa_saque_api' => (float) ($user->taxa_saque_api !== null
                    ? $user->taxa_saque_api 
                    : ($setting->taxa_saque_api_padrao ?? 5.00)),
                'taxa_saque_crypto' => (float) ($user->taxa_saque_crypto !== null
                    ? $user->taxa_saque_crypto 
                    : ($setting->taxa_saque_cripto_padrao ?? 1.00)),
                // Sistema Flexível
                'sistema_flexivel_ativo' => (bool) ($user->sistema_flexivel_ativo ?? false),
                'valor_minimo_flexivel' => (float) ($user->valor_minimo_flexivel !== null
                    ? $user->valor_minimo_flexivel 
                    : ($setting->taxa_flexivel_valor_minimo ?? 15.00)),
                'taxa_fixa_baixos' => (float) ($user->taxa_fixa_baixos !== null
                    ? $user->taxa_fixa_baixos 
                    : ($setting->taxa_flexivel_fixa_baixo ?? 1.00)),
                'taxa_percentual_altos' => (float) ($user->taxa_percentual_altos !== null
                    ? $user->taxa_percentual_altos 
                    : ($setting->taxa_flexivel_percentual_alto ?? 4.00)),
                // Afiliados
                'is_affiliate' => (bool) ($user->is_affiliate ?? false),
                'affiliate_percentage' => (float) ($user->affiliate_percentage ?? 0),
                'affiliate_code' => $user->affiliate_code,
                'affiliate_link' => $user->affiliate_link,
                // Observações
                'observacoes_taxas' => $user->observacoes_taxas ?? null,
            ];

            return $this->successResponse(['user' => $detail]);
            
        } catch (\Exception $e) {
            Log::error('Erro ao obter usuário', [
                'user_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return $this->errorResponse('Erro ao obter usuário', 500);
        }
    }
    
    /**
     * Obter taxas padrão do sistema
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDefaultFees(Request $request)
    {
        try {
            // Verificação de admin feita pelo middleware 'ensure.admin'
            $setting = AppSettingsHelper::getSettings();
            
            if (!$setting) {
                return $this->errorResponse('Configurações do sistema não encontradas', 404);
            }
            
            // Retornar todas as taxas padrão do sistema
            $defaultFees = [
                // Taxas de depósito
                'taxa_percentual_deposito' => (float) ($setting->taxa_cash_in_padrao ?? 4.00),
                'taxa_fixa_deposito' => (float) ($setting->taxa_fixa_padrao ?? 0.00),
                'valor_minimo_deposito' => (float) ($setting->deposito_minimo ?? 1.00),
                // Taxas de saque
                'taxa_percentual_pix' => (float) ($setting->taxa_cash_out_padrao ?? 4.00),
                'taxa_minima_pix' => 0.80, // Valor fixo padrão
                'taxa_fixa_pix' => (float) ($setting->taxa_fixa_pix ?? 0.00),
                'valor_minimo_saque' => (float) ($setting->saque_minimo ?? 1.00),
                // Limites e extras
                'limite_mensal_pf' => (float) ($setting->limite_saque_mensal ?? 50000.00),
                'taxa_saque_api' => (float) ($setting->taxa_saque_api_padrao ?? 5.00),
                'taxa_saque_crypto' => (float) ($setting->taxa_saque_cripto_padrao ?? 1.00),
                // Sistema flexível
                'sistema_flexivel_ativo' => (bool) ($setting->taxa_flexivel_ativa ?? false),
                'valor_minimo_flexivel' => (float) ($setting->taxa_flexivel_valor_minimo ?? 15.00),
                'taxa_fixa_baixos' => (float) ($setting->taxa_flexivel_fixa_baixo ?? 1.00),
                'taxa_percentual_altos' => (float) ($setting->taxa_flexivel_percentual_alto ?? 4.00),
            ];
            
            return $this->successResponse(['fees' => $defaultFees]);
            
        } catch (\Exception $e) {
            Log::error('Erro ao obter taxas padrão', [
                'error' => $e->getMessage()
            ]);
            
            return $this->errorResponse('Erro ao obter taxas padrão', 500);
        }
    }
    
    /**
     * Criar novo usuário
     * 
     * @param StoreUserRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeUser(StoreUserRequest $request)
    {
        try {
            $user = $this->userService->createUser($request->validated());
            
            return $this->successResponse([
                'message' => 'Usuário criado com sucesso',
                'user' => $user
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erro ao criar usuário', [
                'error' => $e->getMessage()
            ]);
            
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
    
    /**
     * Atualizar usuário existente
     * 
     * @param UpdateUserRequest $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateUser(UpdateUserRequest $request, int $id)
    {
        try {
            $user = $this->userService->updateUser($id, $request->validated());
            
            return $this->successResponse([
                'message' => 'Usuário atualizado com sucesso',
                'user' => $user
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar usuário', [
                'user_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
    
    /**
     * Deletar usuário
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteUser(Request $request, int $id)
    {
        try {
            // Verificação de admin feita pelo middleware 'ensure.admin'
            $this->userService->deleteUser($id);
            
            return $this->successResponse([
                'message' => 'Usuário deletado com sucesso'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erro ao deletar usuário', [
                'user_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
    
    /**
     * Aprovar usuário pendente
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function approveUser(Request $request, int $id)
    {
        try {
            // Verificação de admin feita pelo middleware 'ensure.admin'
            $userData = $this->userService->approveUser($id);
            
            // MELHORIA: Disparar evento de aprovação (implementar listener/notification)
            // Evento criado, mas listener precisa ser implementado para enviar notificação
            try {
                event(new \App\Events\UserApproved($userData));
            } catch (\Exception $e) {
                // Se o listener não estiver implementado, não quebra o fluxo
                Log::warning('Evento UserApproved disparado, mas listener pode não estar implementado', [
                    'user_id' => $id,
                    'error' => $e->getMessage()
                ]);
            }
            
            return $this->successResponse([
                'message' => 'Usuário aprovado com sucesso',
                'user' => $userData
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erro ao aprovar usuário', [
                'user_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
    
    /**
     * Bloquear/desbloquear usuário
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleBlockUser(Request $request, int $id)
    {
        try {
            // Verificação de admin feita pelo middleware 'ensure.admin'
            $block = $request->input('block', true);
            $approve = $request->input('approve', false);
            $userData = $this->userService->toggleUserBlock($id, $block, $approve);
            
            $message = $block 
                ? 'Usuário bloqueado com sucesso' 
                : ($approve 
                    ? 'Usuário desbloqueado e aprovado com sucesso' 
                    : 'Usuário desbloqueado com sucesso');
            
            return $this->successResponse([
                'message' => $message,
                'user' => $userData
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erro ao bloquear/desbloquear usuário', [
                'user_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
    
    /**
     * Ajustar saldo do usuário
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function adjustBalance(Request $request, int $id)
    {
        try {
            // Verificação de admin feita pelo middleware 'ensure.admin'
            $request->validate([
                'amount' => 'required|numeric|min:0.01',
                'type' => 'required|in:add,subtract',
                'reason' => 'nullable|string|max:500'
            ]);
            
            $userData = $this->userService->adjustBalance(
                $id,
                $request->input('amount'),
                $request->input('type'),
                $request->input('reason', '')
            );
            
            return $this->successResponse([
                'message' => 'Saldo ajustado com sucesso',
                'user' => $userData
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erro ao ajustar saldo', [
                'user_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Salvar configurações de afiliados
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveAffiliateSettings(AffiliateSettingsRequest $request, int $id)
    {
        try {
            // Validação e autorização já feitas pelo Form Request e middleware
            $admin = $request->user() ?? $request->user_auth;
            $user = User::findOrFail($id);
            
            // Validação já feita pelo Form Request
            $isAffiliate = $request->input('is_affiliate', false);
            $affiliatePercentage = $request->input('affiliate_percentage', 0);
            
            if ($isAffiliate) {
                // Validar porcentagem usando constant (já validado pelo Form Request, mas manter para segurança)
                if (!AffiliateSettings::isValidPercentage($affiliatePercentage)) {
                    return $this->errorResponse(
                        'A porcentagem de affiliate deve estar entre ' . AffiliateSettings::MIN_PERCENTAGE . ' e ' . AffiliateSettings::MAX_PERCENTAGE . '.', 
                        400
                    );
                }
                
                // Gerar código de affiliate se não existe
                if (!$user->affiliate_code) {
                    $codigoBase = strtoupper(substr($user->user_id, 0, 4));
                    $numeroAleatorio = rand(1000, 9999);
                    $codigoCompleto = $codigoBase . $numeroAleatorio;
                    
                    // Verificar se código já existe
                    while (User::where('affiliate_code', $codigoCompleto)->exists()) {
                        $numeroAleatorio = rand(1000, 9999);
                        $codigoCompleto = $codigoBase . $numeroAleatorio;
                    }
                    
                    $user->affiliate_code = $codigoCompleto;
                    $user->affiliate_link = config('app.url') . '/register?ref=' . $user->affiliate_code;
                }
                
                // Atualizar campos de affiliate
                $user->update([
                    'is_affiliate' => true,
                    'affiliate_percentage' => $affiliatePercentage,
                    'affiliate_code' => $user->affiliate_code,
                    'affiliate_link' => $user->affiliate_link
                ]);

                Log::info('[ADMIN AFILIADOS API] Affiliate configurado pelo admin', [
                    'user_id' => $user->id,
                    'affiliate_percentage' => $affiliatePercentage,
                    'affiliate_code' => $user->affiliate_code,
                    'admin_id' => $admin->id ?? null
                ]);
            } else {
                // Desativar affiliate
                $user->update([
                    'is_affiliate' => false,
                    'affiliate_percentage' => 0
                ]);

                Log::info('[ADMIN AFILIADOS API] Affiliate desativado pelo admin', [
                    'user_id' => $user->id,
                    'admin_id' => $admin->id ?? null
                ]);
            }
            
            // Limpar cache
            CacheKeyService::forgetUser($id);
            CacheKeyService::forgetUsersStats();
            
            // Buscar usuário atualizado
            $updatedUser = $this->userService->getUserById($id, true);
            
            return $this->successResponse([
                'message' => 'Configurações de afiliados salvas com sucesso!',
                'user' => [
                    'is_affiliate' => (bool) ($updatedUser->is_affiliate ?? false),
                    'affiliate_percentage' => (float) ($updatedUser->affiliate_percentage ?? 0),
                    'affiliate_code' => $updatedUser->affiliate_code,
                    'affiliate_link' => $updatedUser->affiliate_link,
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('[ADMIN AFILIADOS API] Erro ao salvar configurações de afiliados', [
                'user_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return $this->errorResponse('Erro ao salvar configurações de afiliados.', 500);
        }
    }
}

