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
use Illuminate\Support\Facades\{Cache, DB, Log};
use Carbon\Carbon;

/**
 * Controller para Dashboard Administrativo
 * 
 * Implementa boas práticas:
 * - Cache Redis para performance
 * - Query optimization com índices
 * - Service Layer Pattern
 * - Clean Code
 * - Escalabilidade e Manutenibilidade
 */
class AdminDashboardController extends Controller
{
    // Constantes para TTL de cache
    private const CACHE_TTL_DASHBOARD = 120; // 2 minutos
    private const CACHE_TTL_USERS = 300; // 5 minutos
    private const CACHE_TTL_RECENT_TRANSACTIONS = 30; // 30 segundos
    
    // Constantes para validação
    private const MAX_TRANSACTIONS_LIMIT = 100;
    private const MIN_TRANSACTIONS_LIMIT = 1;
    
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
            
            // Usar Cache facade (padronizado - usa Redis se configurado)
            try {
                $stats = Cache::remember($cacheKey, self::CACHE_TTL_DASHBOARD, function () use ($dataInicio, $dataFim) {
                    return $this->calculateDashboardStats($dataInicio, $dataFim);
                });
                
                return $this->successResponse($stats);
            } catch (\Exception $e) {
                Log::warning('Erro ao usar cache, calculando diretamente', ['error' => $e->getMessage()]);
                // Fallback: calcular sem cache
                $stats = $this->calculateDashboardStats($dataInicio, $dataFim);
                return $this->successResponse($stats);
            }

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
            // Verificação de admin ou gerente feita pelo middleware 'ensure.admin_or_manager'
            $status = $request->input('status');
            $search = $request->input('search');
            $gerenteId = $request->input('gerente_id');
            $perPage = $request->input('per_page', 20);
            $orderBy = $request->input('order_by', 'created_at');
            $orderDirection = $request->input('order_direction', 'desc');

            $query = User::query();

            // Filtros
            if ($status !== null) {
                $query->where('status', $status);
            }

            if ($gerenteId !== null) {
                $query->where('gerente_id', $gerenteId);
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
            // CORRIGIDO: Incluir COMPLETED para consistência
            $userIds = $users->pluck('user_id');
            $vendas7d = Solicitacoes::whereIn('user_id', $userIds)
                ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
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
                    'saque_bloqueado' => (bool) ($user->saque_bloqueado ?? false),
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
     * Obter métricas de cache Redis
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCacheMetrics(Request $request)
    {
        try {
            $cacheMetricsService = app(\App\Services\CacheMetricsService::class);
            
            // Obter métricas gerais (sempre retorna array, mesmo em caso de erro)
            $metrics = $cacheMetricsService->getCacheMetrics();
            
            // Obter métricas financeiras (sempre retorna array, mesmo em caso de erro)
            $financialMetrics = $cacheMetricsService->getFinancialCacheMetrics();
            
            // Garantir que sempre retornamos uma estrutura válida
            return $this->successResponse([
                'general' => $metrics ?? [
                    'redis_connected' => false,
                    'error' => 'Erro ao obter métricas gerais',
                ],
                'financial' => $financialMetrics ?? [
                    'total_financial_keys' => 0,
                    'wallets_keys' => 0,
                    'stats_keys' => 0,
                    'error' => 'Erro ao obter métricas financeiras',
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao obter métricas de cache', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Retornar estrutura válida mesmo em caso de erro
            return $this->successResponse([
                'general' => [
                    'redis_connected' => false,
                    'error' => $e->getMessage(),
                ],
                'financial' => [
                    'total_financial_keys' => 0,
                    'wallets_keys' => 0,
                    'stats_keys' => 0,
                    'error' => $e->getMessage(),
                ],
            ]);
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
            // Verificação de admin ou gerente feita pelo middleware 'ensure.admin_or_manager'
            // Cache key para estatísticas de usuários usando CacheKeyService
            $cacheKey = CacheKeyService::adminUsersStats();
            
            // Usar Cache facade (padronizado - usa Redis se configurado)
            try {
                $stats = Cache::remember($cacheKey, 300, function () {
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

                    return [
                        'total_registrations' => $totalRegistrations,
                        'month_registrations' => $monthRegistrations,
                        'pending_registrations' => $pendingRegistrations,
                        'banned_users' => $bannedUsers,
                    ];
                });
                
                return $this->successResponse($stats);
            } catch (\Exception $e) {
                Log::warning('Erro ao usar cache, calculando diretamente', ['error' => $e->getMessage()]);
                // Fallback: calcular sem cache
                $totalRegistrations = User::count();
                $monthRegistrations = User::whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->count();
                $pendingRegistrations = User::where('status', UserStatus::PENDING)->count();
                $bannedUsers = User::where('banido', true)->count();
                
                $stats = [
                    'total_registrations' => $totalRegistrations,
                    'month_registrations' => $monthRegistrations,
                    'pending_registrations' => $pendingRegistrations,
                    'banned_users' => $bannedUsers,
                ];
                
                return $this->successResponse($stats);
            }

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
     * ✅ MELHORIAS APLICADAS:
     * - Validação de entrada
     * - Cache Redis para performance
     * - Logging melhorado com contexto
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRecentTransactions(Request $request)
    {
        try {
            // Verificação de admin feita pelo middleware 'ensure.admin'
            
            // VALIDAÇÃO DE ENTRADA
            $validated = $this->validateTransactionFilters($request);
            $type = $validated['type'];
            $status = $validated['status'];
            $limit = $validated['limit'];
            
            // CACHE: Gerar cache key baseada nos filtros
            $cacheKey = CacheKeyService::adminRecentTransactions($type, $status, $limit);
            
            // CACHE: Usar cache com TTL curto (30s) para dados recentes
            $transactions = Cache::remember($cacheKey, self::CACHE_TTL_RECENT_TRANSACTIONS, function () use ($type, $status, $limit) {
                // Buscar depósitos e saques separadamente, já ordenados no banco
                $deposits = collect();
                $withdraws = collect();

                // Buscar depósitos
                // IMPORTANTE: Selecionar explicitamente os campos necessários para garantir que sejam carregados
                if (!$type || $type === 'deposit') {
                    $deposits = Solicitacoes::with(['user' => function ($query) {
                        $query->select('id', 'user_id', 'name', 'username');
                    }])
                        ->select([
                            'id',
                            'user_id',
                            'amount',
                            'taxa_cash_in',
                            'taxa_pix_cash_in_adquirente',
                            'adquirente_ref',
                            'executor_ordem',
                            'status',
                            'date',
                            'created_at'
                        ])
                        ->when($status, fn($q) => $q->where('status', $status))
                        ->orderBy('created_at', 'desc')
                        ->limit($limit)
                        ->get()
                        ->map(fn($item) => $this->formatTransaction($item, 'deposit'));
                }

                // Buscar saques
                // IMPORTANTE: Selecionar explicitamente os campos necessários para garantir que sejam carregados
                if (!$type || $type === 'withdraw') {
                    $withdraws = SolicitacoesCashOut::with(['user' => function ($query) {
                        $query->select('id', 'user_id', 'name', 'username');
                    }])
                        ->select([
                            'id',
                            'user_id',
                            'amount',
                            'taxa_cash_out',
                            'status',
                            'date',
                            'created_at'
                        ])
                        ->when($status, fn($q) => $q->where('status', $status))
                        ->orderBy('created_at', 'desc')
                        ->limit($limit)
                        ->get()
                        ->map(fn($item) => $this->formatTransaction($item, 'withdraw'));
                }

                // Converter para Collection padrão antes de mesclar
                $depositsArray = $deposits->toArray();
                $withdrawsArray = $withdraws->toArray();
                
                // Usar Collection padrão do Laravel (não Eloquent Collection)
                $allTransactions = collect(array_merge($depositsArray, $withdrawsArray));
                
                // Ordenar usando closure para acessar corretamente o array
                return $allTransactions
                    ->sortByDesc(fn($item) => $item['created_at_timestamp'] ?? 0)
                    ->take($limit)
                    ->values()
                    ->map(function ($item) {
                        // Remover created_at_timestamp antes de retornar
                        unset($item['created_at_timestamp']);
                        return $item;
                    })
                    ->toArray();
            });

            return $this->successResponse(['transactions' => $transactions]);

        } catch (\Exception $e) {
            Log::error('Erro ao obter transações recentes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'filters' => [
                    'type' => $request->input('type'),
                    'status' => $request->input('status'),
                    'limit' => $request->input('limit'),
                ],
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
        // CORRIGIDO: Incluir COMPLETED para consistência com dashboard do usuário
        $solicitacoes = Solicitacoes::whereIn('status', ['PAID_OUT', 'COMPLETED'])
            ->whereBetween('date', [$dataInicio, $dataFim]);

        // CORRIGIDO: Incluir PAID_OUT para consistência com dashboard do usuário
        $saques = SolicitacoesCashOut::whereIn('status', ['PAID_OUT', 'COMPLETED'])
            ->whereBetween('date', [$dataInicio, $dataFim]);

        // Calcular lucros com uma única query usando aggregates
        // IMPORTANTE: Usar taxa_cash_in - custo TREEAL para obter o lucro líquido
        // O custo da TREEAL será calculado separadamente contando todas as transações TREEAL
        $custoTreealPorTransacao = (float) config('treeal.custo_fixo_por_transacao');
        
        // Contar transações TREEAL separadamente para garantir que todas sejam contabilizadas
        // Verificar tanto por adquirente_ref quanto por executor_ordem para pegar todas as transações
        $totalDepositosTreeal = (clone $solicitacoes)
            ->where(function($query) {
                $query->where('adquirente_ref', 'Treeal')
                      ->orWhere('executor_ordem', 'Treeal');
            })
            ->count();
        
        // Calcular lucro líquido por transação: taxa_cash_in - custo TREEAL
        // IMPORTANTE: Para transações TREEAL, usar custo fixo se taxa_pix_cash_in_adquirente for NULL ou 0
        // Isso garante que transações antigas sem o campo sejam tratadas corretamente
        $depositStats = (clone $solicitacoes)
            ->select(
                DB::raw('SUM(taxa_cash_in) as taxa_total_depositos'),
                DB::raw('SUM(
                    CASE 
                        WHEN (adquirente_ref = \'Treeal\' OR executor_ordem = \'Treeal\') 
                             AND (taxa_pix_cash_in_adquirente IS NULL OR taxa_pix_cash_in_adquirente = 0)
                        THEN ' . $custoTreealPorTransacao . '
                        WHEN taxa_pix_cash_in_adquirente IS NOT NULL AND taxa_pix_cash_in_adquirente > 0
                        THEN taxa_pix_cash_in_adquirente
                        ELSE 0
                    END
                ) as custo_total_treeal'),
                DB::raw('SUM(taxa_cash_in - 
                    CASE 
                        WHEN (adquirente_ref = \'Treeal\' OR executor_ordem = \'Treeal\') 
                             AND (taxa_pix_cash_in_adquirente IS NULL OR taxa_pix_cash_in_adquirente = 0)
                        THEN ' . $custoTreealPorTransacao . '
                        WHEN taxa_pix_cash_in_adquirente IS NOT NULL AND taxa_pix_cash_in_adquirente > 0
                        THEN taxa_pix_cash_in_adquirente
                        ELSE 0
                    END
                ) as lucro_depositos'),
                DB::raw('SUM(amount) as valor_total_depositos'),
                DB::raw('COUNT(*) as total_depositos')
            )
            ->first();
        
        // Lucro líquido já está calculado na query acima
        $lucroDepositos = $depositStats->lucro_depositos ?? 0;

        // Para saques: taxa_cash_out já é o valor total, mas precisamos subtrair o custo da TREEAL (2 centavos por transação)
        // IMPORTANTE: TODOS os saques são processados pela TREEAL, incluindo os manuais
        // Portanto, TODOS os saques têm custo de 2 centavos por transação
        $withdrawStats = (clone $saques)
            ->select(
                DB::raw('SUM(taxa_cash_out) as taxa_total_saques'),
                DB::raw('COUNT(*) as total_saques'),
                DB::raw('SUM(amount) as valor_total_saques')
            )
            ->first();

        $totalSaques = $withdrawStats->total_saques ?? 0;
        $taxaTotalSaques = $withdrawStats->taxa_total_saques ?? 0;
        // TODOS os saques são processados pela TREEAL, então todos têm custo de 2 centavos por transação
        $custoTreealSaques = $totalSaques * $custoTreealPorTransacao;
        $lucroSaques = $taxaTotalSaques - $custoTreealSaques;

        // Calcular taxas pagas aos adquirentes
        // Usar o custo total já calculado na query
        $custoTotalTreealDepositos = $depositStats->custo_total_treeal ?? ($totalDepositosTreeal * $custoTreealPorTransacao);
        // TODOS os saques são processados pela TREEAL, então passar total_saques (não apenas total_saques_treeal)
        $taxasAdquirentes = $this->calculateAcquirerFees($solicitacoes, $saques, $custoTotalTreealDepositos, $totalSaques, $custoTreealPorTransacao);
        
        // Lucro líquido total
        $lucroLiquido = $lucroDepositos + $lucroSaques;

        // Saldo total em carteiras (usando Cache facade padronizado)
        $balanceCacheKey = CacheKeyService::totalWalletsBalance();
        try {
            $saldoTotalCarteiras = Cache::remember($balanceCacheKey, 300, function () {
                return (float) User::sum('saldo');
            });
        } catch (\Exception $e) {
            Log::warning('Erro ao usar cache de saldo, usando cálculo direto', ['error' => $e->getMessage()]);
            $saldoTotalCarteiras = (float) User::sum('saldo');
        }

        // Log para debug
        \Illuminate\Support\Facades\Log::info('AdminDashboardController::calculateFinancialStats - Cálculo final', [
            'total_depositos' => $depositStats->total_depositos ?? 0,
            'total_depositos_treeal' => $totalDepositosTreeal,
            'taxa_total_depositos' => $depositStats->taxa_total_depositos ?? 0,
            'custo_total_treeal_depositos' => $custoTotalTreealDepositos,
            'lucro_depositos' => $lucroDepositos,
            'total_saques' => $totalSaques,
            'taxa_total_saques' => $taxaTotalSaques,
            'custo_treeal_saques' => $custoTreealSaques,
            'lucro_saques' => $lucroSaques,
            'lucro_liquido_total' => $lucroLiquido,
            'taxas_adquirentes' => $taxasAdquirentes,
        ]);

        return [
            'saldo_carteiras' => (float) $saldoTotalCarteiras,
            'lucro_liquido' => (float) $lucroLiquido, // Já é o lucro líquido (taxas - custos TREEAL)
            'lucro_depositos' => (float) $lucroDepositos, // Lucro líquido de depósitos (taxa_cash_in - custo TREEAL)
            'lucro_saques' => (float) $lucroSaques, // Lucro líquido de saques (taxa_cash_out - custo TREEAL)
            'taxas_adquirentes' => [
                'entradas' => (float) $taxasAdquirentes['entradas'], // Custo TREEAL em depósitos
                'saidas' => (float) $taxasAdquirentes['saidas'], // Custo TREEAL em saques
                'total' => (float) ($taxasAdquirentes['entradas'] + $taxasAdquirentes['saidas']),
            ],
        ];
    }
    
    /**
     * Calcular estatísticas de transações
     */
    private function calculateTransactionStats(Carbon $dataInicio, Carbon $dataFim): array
    {
        // CORRIGIDO: Incluir COMPLETED para consistência com dashboard do usuário
        $solicitacoes = Solicitacoes::whereIn('status', ['PAID_OUT', 'COMPLETED'])
            ->whereBetween('date', [$dataInicio, $dataFim]);

        // CORRIGIDO: Incluir PAID_OUT para consistência com dashboard do usuário
        $saques = SolicitacoesCashOut::whereIn('status', ['PAID_OUT', 'COMPLETED'])
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
     * Calcula o custo total pago à TREEAL (2 centavos por transação PIX)
     * IMPORTANTE: Usa o custo total já calculado na query para garantir precisão
     * 
     * @param $solicitacoes Query builder de solicitações (não usado, mantido para compatibilidade)
     * @param $saques Query builder de saques (não usado, mantido para compatibilidade)
     * @param float $custoTotalTreealDepositos Custo total da TREEAL em depósitos já calculado
     * @param int $totalSaques Total de saques (TODOS os saques são processados pela TREEAL)
     * @param float $custoTreealPorTransacao Custo fixo da TREEAL por transação (obtido da config)
     * @return array
     */
    private function calculateAcquirerFees($solicitacoes, $saques, float $custoTotalTreealDepositos = 0, int $totalSaques = 0, float $custoTreealPorTransacao = null): array
    {
        // Se não foi passado, obter da config
        if ($custoTreealPorTransacao === null) {
            $custoTreealPorTransacao = (float) config('treeal.custo_fixo_por_transacao');
        }
        
        // Taxas de adquirentes em depósitos = custo total já calculado na query
        // Isso garante que todas as transações sejam contabilizadas, mesmo com taxa_pix_cash_in_adquirente NULL
        $taxasAdquirenteDepositos = $custoTotalTreealDepositos;
        
        // Taxas de adquirentes em saques (custo fixo da TREEAL * número total de saques)
        // IMPORTANTE: TODOS os saques são processados pela TREEAL, incluindo os manuais
        $taxasAdquirenteSaques = $totalSaques * $custoTreealPorTransacao;
        
        return [
            'entradas' => (float) $taxasAdquirenteDepositos,
            'saidas' => (float) $taxasAdquirenteSaques,
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

    // =====================================================
    // CRUD DE USUÁRIOS
    // =====================================================
    
    /**
     * Listar gerentes (permission = 2)
     * 
     * Implementa:
     * - Cache Redis para performance
     * - Validação de entrada
     * - Query otimizada com índices
     * - Paginação
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function listManagers(Request $request)
    {
        try {
            // Validar e sanitizar inputs
            $perPage = max(1, min((int) $request->input('per_page', 50), 100));
            $page = max(1, (int) $request->input('page', 1));
            $search = trim($request->input('search', ''));
            
            // Gerar cache key
            $cacheKey = CacheKeyService::managersList([
                'per_page' => $perPage,
                'page' => $page,
                'search' => $search,
            ]);
            
            // Usar cache com TTL de 2 minutos
            $result = Cache::remember($cacheKey, 120, function () use ($perPage, $page, $search) {
                // Query otimizada: usar índice em permission
                $query = User::where('permission', UserPermission::MANAGER);
                
                // Aplicar filtro de busca
                if (!empty($search)) {
                    $query->where(function($q) use ($search) {
                        $searchTerm = "%{$search}%";
                        $q->where('name', 'like', $searchTerm)
                          ->orWhere('email', 'like', $searchTerm)
                          ->orWhere('username', 'like', $searchTerm)
                          ->orWhere('cpf_cnpj', 'like', $searchTerm);
                    });
                }
                
                // Ordenar por nome (usar índice se disponível)
                $managers = $query->orderBy('name', 'asc')
                    ->paginate($perPage, [
                        'id', 
                        'name', 
                        'username', 
                        'email', 
                        'cpf_cnpj', 
                        'telefone', 
                        'status', 
                        'permission',
                        'gerente_percentage',
                        'created_at'
                    ], 'page', $page);
                
                return [
                    'managers' => $managers->items(),
                    'pagination' => [
                        'current_page' => $managers->currentPage(),
                        'per_page' => $managers->perPage(),
                        'total' => $managers->total(),
                        'last_page' => $managers->lastPage(),
                    ]
                ];
            });
            
            return $this->successResponse($result);
            
        } catch (\Exception $e) {
            Log::error('Erro ao listar gerentes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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
            // Verificação de admin ou gerente feita pelo middleware 'ensure.admin_or_manager'
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
                'saque_bloqueado' => (bool) ($user->saque_bloqueado ?? false),
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
                // Taxas fixas (em centavos) - SEMPRE retornar valores salvos no banco (se existirem), senão usar padrão
                'taxas_personalizadas_ativas' => $usandoPersonalizadas,
                // Taxa fixa de depósito
                'taxa_fixa_deposito' => (float) ($user->taxa_fixa_deposito !== null 
                    ? $user->taxa_fixa_deposito 
                    : ($setting->taxa_fixa_padrao ?? 0.00)),
                // Taxa fixa de saque
                'taxa_fixa_pix' => (float) ($user->taxa_fixa_pix !== null
                    ? $user->taxa_fixa_pix 
                    : ($setting->taxa_fixa_pix ?? 0.00)),
                // Limites
                'limite_mensal_pf' => (float) ($user->limite_mensal_pf !== null
                    ? $user->limite_mensal_pf 
                    : ($setting->limite_saque_mensal ?? 10000000.00)), // 10 milhões de reais
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
            // Verificação de admin ou gerente feita pelo middleware 'ensure.admin_or_manager'
            $setting = AppSettingsHelper::getSettings();
            
            if (!$setting) {
                return $this->errorResponse('Configurações do sistema não encontradas', 404);
            }
            
            // Retornar taxas padrão do sistema (apenas fixas em centavos)
            $defaultFees = [
                // Taxa fixa de depósito
                'taxa_fixa_deposito' => (float) ($setting->taxa_fixa_padrao ?? 0.00),
                // Taxa fixa de saque
                'taxa_fixa_pix' => (float) ($setting->taxa_fixa_pix ?? 0.00),
                // Limites
                'limite_mensal_pf' => (float) ($setting->limite_saque_mensal ?? 10000000.00), // 10 milhões de reais
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
            $validatedData = $request->validated();
            
            // Validar taxas individuais se estiverem presentes
            $taxFields = [
                'taxas_personalizadas_ativas', 'taxa_percentual_deposito', 'taxa_fixa_deposito',
                'valor_minimo_deposito', 'taxa_percentual_pix', 'taxa_minima_pix', 'taxa_fixa_pix',
                'limite_mensal_pf', 'taxa_saque_api', 'taxa_saque_crypto',
                'sistema_flexivel_ativo', 'valor_minimo_flexivel', 'taxa_fixa_baixos', 'taxa_percentual_altos'
            ];
            
            $hasTaxFields = false;
            foreach ($taxFields as $field) {
                if (array_key_exists($field, $validatedData)) {
                    $hasTaxFields = true;
                    break;
                }
            }
            
            if ($hasTaxFields) {
                // Validar taxas individuais
                $taxValidator = \App\Services\TaxValidationService::validateIndividualTaxes($validatedData);
                if ($taxValidator->fails()) {
                    return $this->errorResponse('Erro de validação nas taxas: ' . $taxValidator->errors()->first(), 422);
                }
                
                // Validar consistência das taxas
                $consistencyCheck = \App\Services\TaxValidationService::validateTaxConsistency($validatedData);
                if (!$consistencyCheck['valid']) {
                    return $this->errorResponse('Inconsistência nas taxas: ' . implode(' ', $consistencyCheck['errors']), 422);
                }
                
                // Sanitizar dados de taxas
                $validatedData = \App\Services\TaxValidationService::sanitizeTaxData($validatedData);
            }
            
            // Verificação de admin ou gerente feita pelo middleware 'ensure.admin_or_manager'
            $user = $this->userService->updateUser($id, $validatedData);
            
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
            // Verificação de admin ou gerente feita pelo middleware 'ensure.admin_or_manager'
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
     * Bloquear/desbloquear saque do usuário
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleWithdrawBlock(Request $request, int $id)
    {
        try {
            // Verificação de admin ou gerente feita pelo middleware 'ensure.admin_or_manager'
            $block = $request->input('block', true);
            $userData = $this->userService->toggleWithdrawBlock($id, $block);
            
            $message = $block 
                ? 'Saque do usuário bloqueado com sucesso' 
                : 'Saque do usuário desbloqueado com sucesso';
            
            return $this->successResponse([
                'message' => $message,
                'user' => $userData
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erro ao bloquear/desbloquear saque do usuário', [
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
    
    // ========== Métodos Privados (Helpers) ==========
    
    /**
     * @param mixed $item Modelo Eloquent (Solicitacoes ou SolicitacoesCashOut)
     * @param string $type 'deposit' ou 'withdraw'
     * @return array
     */
    private function formatTransaction($item, string $type): array
    {
        // Extrair dados do usuário
        $userData = null;
        if ($item->user && is_object($item->user)) {
            $userData = [
                'id' => $item->user->id ?? null,
                'name' => $item->user->name ?? null,
                'username' => $item->user->username ?? null,
            ];
        }
        
        // Acessar campos de forma explícita para garantir que os valores sejam carregados
        $amount = $type === 'deposit' 
            ? (float) ($item->getAttribute('amount') ?? $item->amount ?? 0)
            : (float) ($item->getAttribute('amount') ?? $item->amount ?? 0);
        
        $taxa = $type === 'deposit'
            ? (float) ($item->getAttribute('taxa_cash_in') ?? $item->taxa_cash_in ?? 0)
            : (float) ($item->getAttribute('taxa_cash_out') ?? $item->taxa_cash_out ?? 0);
        
        // Calcular custo TREEAL e lucro líquido
        $custoTreealPorTransacao = (float) config('treeal.custo_fixo_por_transacao');
        $custoTreeal = 0;
        $lucroLiquido = $taxa;
        
        if ($type === 'deposit') {
            // Para depósitos: verificar se é transação TREEAL
            $adquirenteRef = $item->getAttribute('adquirente_ref') ?? $item->adquirente_ref ?? '';
            $executorOrdem = $item->getAttribute('executor_ordem') ?? $item->executor_ordem ?? '';
            $isTreeal = $adquirenteRef === 'Treeal' || $executorOrdem === 'Treeal';
            
            if ($isTreeal) {
                // Se tem taxa_pix_cash_in_adquirente, usar ela; senão, usar custo fixo
                $taxaAdquirente = $item->getAttribute('taxa_pix_cash_in_adquirente') ?? $item->taxa_pix_cash_in_adquirente ?? null;
                if ($taxaAdquirente !== null && $taxaAdquirente > 0) {
                    $custoTreeal = (float) $taxaAdquirente;
                } else {
                    $custoTreeal = $custoTreealPorTransacao;
                }
                $lucroLiquido = $taxa - $custoTreeal;
            }
        } else {
            // Para saques: TODOS são processados pela TREEAL
            $custoTreeal = $custoTreealPorTransacao;
            $lucroLiquido = $taxa - $custoTreeal;
        }
        
        // Log para debug (remover em produção se necessário)
        \Illuminate\Support\Facades\Log::debug('AdminDashboardController::formatTransaction', [
            'transaction_id' => $item->id ?? null,
            'type' => $type,
            'amount_raw' => $item->amount ?? null,
            'amount_formatted' => $amount,
            'taxa_raw' => $type === 'deposit' ? ($item->taxa_cash_in ?? null) : ($item->taxa_cash_out ?? null),
            'taxa_formatted' => $taxa,
            'custo_treeal' => $custoTreeal,
            'lucro_liquido' => $lucroLiquido,
        ]);
        
        return [
            'id' => $item->id,
            'type' => $type,
            'user' => $userData,
            'amount' => $amount,
            'taxa' => $taxa,
            'custo_treeal' => $custoTreeal,
            'lucro_liquido' => max(0, $lucroLiquido), // Garantir que não seja negativo
            'status' => $item->status ?? null,
            'date' => $item->date ?? null,
            'created_at' => $item->created_at ? $item->created_at->toIso8601String() : null,
            'created_at_timestamp' => $item->created_at ? $item->created_at->timestamp : 0,
        ];
    }
    
    /**
     * VALIDAÇÃO: Validar e sanitizar filtros de transações
     * 
     * @param Request $request
     * @return array
     */
    private function validateTransactionFilters(Request $request): array
    {
        // Validar e sanitizar limit
        $limit = (int) $request->input('limit', 50);
        $limit = max(self::MIN_TRANSACTIONS_LIMIT, min($limit, self::MAX_TRANSACTIONS_LIMIT));
        
        // Validar type (deve ser 'deposit', 'withdraw' ou null)
        $type = $request->input('type');
        if ($type && !in_array($type, ['deposit', 'withdraw'], true)) {
            $type = null; // Invalidar se não for um dos valores permitidos
        }
        
        // Validar status (sanitizar string)
        $status = $request->input('status');
        if ($status) {
            $status = trim((string) $status);
            // Limitar tamanho para evitar SQL injection
            if (strlen($status) > 50) {
                $status = null;
            }
        }
        
        return [
            'type' => $type,
            'status' => $status ?: null,
            'limit' => $limit,
        ];
    }
}

