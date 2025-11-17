<?php

namespace App\Services;

use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Helpers\Helper;
use Illuminate\Support\Facades\{Cache, DB, Log};
use Illuminate\Support\Collection;
use Carbon\Carbon;

/**
 * Service para lógica de negócio financeira
 * 
 * Implementa:
 * - Cache Redis para performance
 * - Queries otimizadas
 * - Clean Code
 */
class FinancialService
{
    // Constantes para TTL de cache
    private const CACHE_TTL_TRANSACTIONS = 60; // 1 minuto
    private const CACHE_TTL_STATS = 120; // 2 minutos
    private const CACHE_TTL_WALLETS = 300; // 5 minutos

    // Status aprovados para cálculos
    private const APPROVED_STATUSES = ['PAID_OUT', 'COMPLETED'];

    /**
     * Obter todas as transações (depósitos + saques) com filtros
     */
    public function getAllTransactions(array $filters): array
    {
        $page = $filters['page'] ?? 1;
        $limit = min($filters['limit'] ?? 20, 100);
        $status = $filters['status'] ?? null;
        $tipo = $filters['tipo'] ?? null;
        $busca = $filters['busca'] ?? null;
        $dataInicio = $filters['data_inicio'] ?? null;
        $dataFim = $filters['data_fim'] ?? null;

        // Cache key baseado nos filtros
        $cacheKey = $this->getTransactionsCacheKey($filters);

        return Cache::remember($cacheKey, self::CACHE_TTL_TRANSACTIONS, function () use (
            $page, $limit, $status, $tipo, $busca, $dataInicio, $dataFim
        ) {
            $deposits = $this->getDepositsQuery($status, $busca, $dataInicio, $dataFim, $tipo);
            $withdrawals = $this->getWithdrawalsQuery($status, $busca, $dataInicio, $dataFim, $tipo);

            // Mesclar e ordenar
            $allTransactions = $deposits->merge($withdrawals)
                ->sortByDesc('created_at')
                ->values();

            // Paginar manualmente (já que mesclamos duas coleções)
            $total = $allTransactions->count();
            $transactions = $allTransactions->forPage($page, $limit)->values();

            return [
                'data' => $transactions->toArray(),
                'current_page' => (int) $page,
                'last_page' => (int) ceil($total / $limit),
                'per_page' => (int) $limit,
                'total' => $total,
            ];
        });
    }

    /**
     * Obter estatísticas de transações
     */
    public function getTransactionsStats(string $periodo = 'hoje'): array
    {
        $cacheKey = "financial:transactions:stats:{$periodo}:" . Carbon::now()->format('Ymd');

        return Cache::remember($cacheKey, self::CACHE_TTL_STATS, function () use ($periodo) {
            $dateRange = $this->getDateRange($periodo);

            // Usar uma única query agregada para melhor performance
            $depositsStats = $this->getDepositsStatsAggregated($dateRange);
            $withdrawalsStats = $this->getWithdrawalsStatsAggregated($dateRange);

            // Calcular lucros
            $lucroHoje = $this->calculateProfit('hoje');
            $lucroMes = $this->calculateProfit('mes');
            $lucroTotal = $this->calculateProfit('total');
            $lucroPeriodo = $depositsStats['lucro'] + $withdrawalsStats['lucro'];

            return [
                'transacoes_aprovadas' => $depositsStats['aprovadas'] + $withdrawalsStats['aprovadas'],
                'lucro_liquido_hoje' => (float) $lucroHoje,
                'lucro_liquido_mes' => (float) $lucroMes,
                'lucro_liquido_total' => (float) $lucroTotal,
                'lucro_liquido_periodo' => (float) $lucroPeriodo,
            ];
        });
    }

    /**
     * Obter carteiras (usuários com saldo)
     * Otimizado: usa cache, select específico e índices
     */
    public function getWallets(array $filters): array
    {
        // Validação e sanitização de entrada
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(max(1, (int) ($filters['limit'] ?? 20)), 100);
        $busca = $filters['busca'] ? trim($filters['busca']) : null;
        $tipoUsuario = $filters['tipo_usuario'] ?? null;
        $ordenar = $filters['ordenar'] ?? 'saldo_desc';

        // Limitar tamanho da busca para evitar queries muito lentas
        if ($busca && mb_strlen($busca) > 100) {
            $busca = mb_substr($busca, 0, 100);
        }

        $cacheKey = $this->getWalletsCacheKey($filters);

        return Cache::remember($cacheKey, self::CACHE_TTL_WALLETS, function () use (
            $page, $limit, $busca, $tipoUsuario, $ordenar
        ) {
            // Select apenas campos necessários para reduzir memória e I/O
            $query = User::query()
                ->select([
                    'id', 'user_id', 'name', 'username', 'email', 'telefone',
                    'saldo', 'total_transacoes', 'valor_sacado',
                    'status', 'permission', 'created_at',
                ])
                ->when($busca, fn($q) => $this->applySearchFilter($q, $busca))
                ->when($tipoUsuario === 'ativo', fn($q) => $q->where('saldo', '>', 0))
                ->when($tipoUsuario === 'inativo', fn($q) => $q->where('saldo', '<=', 0));

            // Aplicar ordenação (usa índice em saldo quando disponível)
            $this->applySorting($query, $ordenar);

            // Paginação eficiente
            $wallets = $query->paginate($limit, ['*'], 'page', $page);

            // Formatação otimizada
            $walletsData = $wallets->getCollection()->map(fn($user) => $this->formatWallet($user));

            return [
                'data' => $walletsData->toArray(),
                'current_page' => $wallets->currentPage(),
                'last_page' => $wallets->lastPage(),
                'per_page' => $wallets->perPage(),
                'total' => $wallets->total(),
            ];
        });
    }

    /**
     * Obter estatísticas de carteiras
     * Otimizado: usa cache e queries eficientes
     */
    public function getWalletsStats(): array
    {
        $cacheKey = 'financial:wallets:stats';

        return Cache::remember($cacheKey, self::CACHE_TTL_STATS, function () {
            // Usar agregados para melhor performance (uma única query)
            $stats = User::selectRaw('
                COUNT(*) as total_carteiras,
                COALESCE(SUM(saldo), 0) as saldo_total,
                SUM(CASE WHEN saldo > 0 THEN 1 ELSE 0 END) as carteiras_ativas,
                COALESCE(AVG(saldo), 0) as valor_medio_carteira
            ')->first();

            // Buscar TOP 3 usuários com maior saldo (query otimizada com índice)
            // Usa apenas campos necessários para reduzir memória
            $top3Users = User::select([
                'id', 'user_id', 'name', 'username', 'email', 'telefone',
                'saldo', 'total_transacoes', 'valor_sacado',
            ])
                ->where('saldo', '>', 0) // Filtrar apenas com saldo positivo
                ->orderBy('saldo', 'desc')
                ->limit(3)
                ->get()
                ->map(fn($user) => [
                    'id' => $user->id,
                    'user_id' => $user->user_id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'telefone' => $user->telefone,
                    'saldo' => (float) $user->saldo,
                    'total_transacoes' => (float) $user->total_transacoes,
                    'valor_sacado' => (float) $user->valor_sacado,
                ]);

            return [
                'total_carteiras' => (int) ($stats->total_carteiras ?? 0),
                'saldo_total' => (float) ($stats->saldo_total ?? 0),
                'carteiras_ativas' => (int) ($stats->carteiras_ativas ?? 0),
                'valor_medio_carteira' => (float) ($stats->valor_medio_carteira ?? 0),
                'top_3_usuarios' => $top3Users->toArray(),
            ];
        });
    }

    /**
     * Obter depósitos (entradas)
     * Otimizado: usa cache Redis, eager loading e select específico
     */
    public function getDeposits(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(max(1, (int) ($filters['limit'] ?? 20)), 100);
        $status = $filters['status'] ?? null;
        $busca = $filters['busca'] ?? null;
        $dataInicio = $filters['data_inicio'] ?? null;
        $dataFim = $filters['data_fim'] ?? null;

        // Cache key baseado nos filtros
        $cacheKey = $this->getDepositsCacheKey($filters);

        return Cache::remember($cacheKey, self::CACHE_TTL_TRANSACTIONS, function () use (
            $page, $limit, $status, $busca, $dataInicio, $dataFim
        ) {
            // Select apenas campos necessários para reduzir memória e I/O
            $query = Solicitacoes::with(['user:id,user_id,name,username'])
                ->select([
                    'id', 'user_id', 'idTransaction', 'amount', 'deposito_liquido',
                    'status', 'date', 'method', 'client_name', 'created_at',
                ])
                ->when($status, fn($q) => $q->where('status', $status))
                ->when($busca, fn($q) => $this->applyDepositSearch($q, $busca))
                ->when($dataInicio, fn($q) => $q->where('date', '>=', $dataInicio))
                ->when($dataFim, fn($q) => $q->where('date', '<=', $dataFim . ' 23:59:59'))
                ->orderBy('date', 'desc'); // Usa índice sol_date_idx

            $deposits = $query->paginate($limit, ['*'], 'page', $page);

            $depositsData = $deposits->getCollection()->map(fn($item) => $this->formatDeposit($item));

            return [
                'data' => $depositsData->toArray(),
                'current_page' => $deposits->currentPage(),
                'last_page' => $deposits->lastPage(),
                'per_page' => $deposits->perPage(),
                'total' => $deposits->total(),
            ];
        });
    }

    /**
     * Obter estatísticas de depósitos
     * Retorna estatísticas gerais (todos os depósitos), hoje e mês
     * Otimizado: usa uma única query com UNION para melhor performance
     */
    public function getDepositsStats(string $periodo = 'hoje'): array
    {
        $cacheKey = "financial:deposits:stats:{$periodo}:" . Carbon::now()->format('Ymd');

        return Cache::remember($cacheKey, self::CACHE_TTL_STATS, function () {
            $now = Carbon::now();
            $hojeInicio = $now->copy()->startOfDay();
            $hojeFim = $now->copy()->endOfDay();
            $mesInicio = $now->copy()->startOfMonth();
            $mesFim = $now->copy()->endOfMonth();

            // Usar uma única query com subqueries para melhor performance
            // Isso reduz o número de round-trips ao banco
            $stats = DB::selectOne("
                SELECT 
                    -- Estatísticas gerais
                    (SELECT COUNT(*) FROM solicitacoes) as total_depositos_geral,
                    (SELECT COUNT(*) FROM solicitacoes WHERE status IN (?, ?)) as depositos_aprovados_geral,
                    (SELECT COALESCE(SUM(amount), 0) FROM solicitacoes WHERE status IN (?, ?)) as valor_total_geral,
                    -- Estatísticas de hoje
                    (SELECT COUNT(*) FROM solicitacoes WHERE date BETWEEN ? AND ? AND status IN (?, ?)) as depositos_aprovados_hoje,
                    (SELECT COALESCE(SUM(amount), 0) FROM solicitacoes WHERE date BETWEEN ? AND ? AND status IN (?, ?)) as valor_total_hoje,
                    -- Estatísticas do mês
                    (SELECT COUNT(*) FROM solicitacoes WHERE date BETWEEN ? AND ? AND status IN (?, ?)) as depositos_aprovados_mes,
                    (SELECT COALESCE(SUM(amount), 0) FROM solicitacoes WHERE date BETWEEN ? AND ? AND status IN (?, ?)) as valor_total_mes
            ", array_merge(
                self::APPROVED_STATUSES, // depositos_aprovados_geral
                self::APPROVED_STATUSES, // valor_total_geral
                [$hojeInicio, $hojeFim], // depositos_aprovados_hoje
                self::APPROVED_STATUSES,
                [$hojeInicio, $hojeFim], // valor_total_hoje
                self::APPROVED_STATUSES,
                [$mesInicio, $mesFim], // depositos_aprovados_mes
                self::APPROVED_STATUSES,
                [$mesInicio, $mesFim], // valor_total_mes
                self::APPROVED_STATUSES
            ));

            return [
                // Estatísticas gerais
                'total_depositos_geral' => (int) ($stats->total_depositos_geral ?? 0),
                'depositos_aprovados_geral' => (int) ($stats->depositos_aprovados_geral ?? 0),
                'valor_total_geral' => (float) ($stats->valor_total_geral ?? 0),
                // Estatísticas de hoje
                'depositos_aprovados_hoje' => (int) ($stats->depositos_aprovados_hoje ?? 0),
                'valor_total_hoje' => (float) ($stats->valor_total_hoje ?? 0),
                // Estatísticas do mês
                'depositos_aprovados_mes' => (int) ($stats->depositos_aprovados_mes ?? 0),
                'valor_total_mes' => (float) ($stats->valor_total_mes ?? 0),
            ];
        });
    }

    /**
     * Obter saques (saídas)
     * Otimizado: usa cache Redis, eager loading e select específico
     */
    public function getWithdrawals(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(max(1, (int) ($filters['limit'] ?? 20)), 100);
        $status = $filters['status'] ?? null;
        $busca = $filters['busca'] ?? null;
        $dataInicio = $filters['data_inicio'] ?? null;
        $dataFim = $filters['data_fim'] ?? null;

        // Cache key baseado nos filtros
        $cacheKey = $this->getWithdrawalsCacheKey($filters);

        return Cache::remember($cacheKey, self::CACHE_TTL_TRANSACTIONS, function () use (
            $page, $limit, $status, $busca, $dataInicio, $dataFim
        ) {
            // Select apenas campos necessários para reduzir memória e I/O
            $query = SolicitacoesCashOut::with(['user:id,user_id,name,username'])
                ->select([
                    'id', 'user_id', 'idTransaction', 'amount', 'valor_liquido',
                    'status', 'date', 'pix_key', 'pix_type', 'taxa_cash_out', 'created_at',
                ])
                ->when($status, fn($q) => $q->where('status', $status))
                ->when($busca, fn($q) => $this->applyWithdrawalSearch($q, $busca))
                ->when($dataInicio, fn($q) => $q->where('date', '>=', $dataInicio))
                ->when($dataFim, fn($q) => $q->where('date', '<=', $dataFim . ' 23:59:59'))
                ->orderBy('date', 'desc');

            $withdrawals = $query->paginate($limit, ['*'], 'page', $page);

            $withdrawalsData = $withdrawals->getCollection()->map(fn($item) => $this->formatWithdrawal($item));

            return [
                'data' => $withdrawalsData->toArray(),
                'current_page' => $withdrawals->currentPage(),
                'last_page' => $withdrawals->lastPage(),
                'per_page' => $withdrawals->perPage(),
                'total' => $withdrawals->total(),
            ];
        });
    }

    /**
     * Obter estatísticas de saques
     */
    public function getWithdrawalsStats(string $periodo = 'hoje'): array
    {
        $cacheKey = "financial:withdrawals:stats:{$periodo}:" . Carbon::now()->format('Ymd');

        return Cache::remember($cacheKey, self::CACHE_TTL_STATS, function () use ($periodo) {
            $dateRange = $this->getDateRange($periodo);

            $stats = SolicitacoesCashOut::whereBetween('date', [$dateRange['inicio'], $dateRange['fim']])
                ->selectRaw('
                    COUNT(*) as total_saques,
                    SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as saques_aprovados,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as saques_pendentes,
                    SUM(CASE WHEN status IN (?, ?) THEN amount ELSE 0 END) as valor_total,
                    SUM(CASE WHEN status IN (?, ?) THEN taxa_cash_out ELSE 0 END) as lucro_saques
                ', array_merge(
                    self::APPROVED_STATUSES,
                    ['PENDING'],
                    self::APPROVED_STATUSES,
                    self::APPROVED_STATUSES
                ))
                ->first();

            return [
                'total_saques' => (int) ($stats->total_saques ?? 0),
                'saques_aprovados' => (int) ($stats->saques_aprovados ?? 0),
                'saques_pendentes' => (int) ($stats->saques_pendentes ?? 0),
                'valor_total' => (float) ($stats->valor_total ?? 0),
                'lucro_saques' => (float) ($stats->lucro_saques ?? 0),
            ];
        });
    }

    // ========== Métodos Privados (Helpers) ==========

    /**
     * Query para depósitos
     */
    private function getDepositsQuery(?string $status, ?string $busca, ?string $dataInicio, ?string $dataFim, ?string $tipo): Collection
    {
        if ($tipo && $tipo !== 'deposito') {
            return collect();
        }

        $query = Solicitacoes::with('user:id,user_id,name,username')
            ->when($status, fn($q) => $q->where('status', $status))
            ->when($busca, fn($q) => $this->applyDepositSearch($q, $busca))
            ->when($dataInicio, fn($q) => $q->where('date', '>=', $dataInicio))
            ->when($dataFim, fn($q) => $q->where('date', '<=', $dataFim . ' 23:59:59'))
            ->orderBy('date', 'desc');

        return $query->get()->map(fn($item) => $this->formatTransaction($item, 'deposito'));
    }

    /**
     * Query para saques
     */
    private function getWithdrawalsQuery(?string $status, ?string $busca, ?string $dataInicio, ?string $dataFim, ?string $tipo): Collection
    {
        if ($tipo && $tipo !== 'saque') {
            return collect();
        }

        $query = SolicitacoesCashOut::with('user:id,user_id,name,username')
            ->when($status, fn($q) => $q->where('status', $status))
            ->when($busca, fn($q) => $this->applyWithdrawalSearch($q, $busca))
            ->when($dataInicio, fn($q) => $q->where('date', '>=', $dataInicio))
            ->when($dataFim, fn($q) => $q->where('date', '<=', $dataFim . ' 23:59:59'))
            ->orderBy('date', 'desc');

        return $query->get()->map(fn($item) => $this->formatTransaction($item, 'saque'));
    }

    /**
     * Estatísticas agregadas de depósitos
     */
    private function getDepositsStatsAggregated(array $dateRange): array
    {
        $stats = Solicitacoes::whereBetween('date', [$dateRange['inicio'], $dateRange['fim']])
            ->selectRaw('
                SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as aprovadas,
                SUM(CASE WHEN status IN (?, ?) THEN (amount - deposito_liquido) ELSE 0 END) as lucro
            ', array_merge(self::APPROVED_STATUSES, self::APPROVED_STATUSES))
            ->first();

        return [
            'aprovadas' => (int) ($stats->aprovadas ?? 0),
            'lucro' => (float) ($stats->lucro ?? 0),
        ];
    }

    /**
     * Estatísticas agregadas de saques
     */
    private function getWithdrawalsStatsAggregated(array $dateRange): array
    {
        $stats = SolicitacoesCashOut::whereBetween('date', [$dateRange['inicio'], $dateRange['fim']])
            ->selectRaw('
                SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as aprovadas,
                SUM(CASE WHEN status IN (?, ?) THEN taxa_cash_out ELSE 0 END) as lucro
            ', array_merge(self::APPROVED_STATUSES, self::APPROVED_STATUSES))
            ->first();

        return [
            'aprovadas' => (int) ($stats->aprovadas ?? 0),
            'lucro' => (float) ($stats->lucro ?? 0),
        ];
    }

    /**
     * Calcular lucro para período específico
     */
    private function calculateProfit(string $periodo): float
    {
        $dateRange = $this->getDateRange($periodo);

        $lucroDepositos = Solicitacoes::whereIn('status', self::APPROVED_STATUSES)
            ->whereBetween('date', [$dateRange['inicio'], $dateRange['fim']])
            ->sum(DB::raw('amount - deposito_liquido'));

        $lucroSaques = SolicitacoesCashOut::whereIn('status', self::APPROVED_STATUSES)
            ->whereBetween('date', [$dateRange['inicio'], $dateRange['fim']])
            ->sum('taxa_cash_out');

        return (float) ($lucroDepositos + $lucroSaques);
    }

    /**
     * Aplicar filtro de busca em depósitos
     * Busca por: cliente, email, documento, transação ID, user_id e relacionamento com usuário
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $busca
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function applyDepositSearch(\Illuminate\Database\Eloquent\Builder $query, string $busca): \Illuminate\Database\Eloquent\Builder
    {
        // SEGURANÇA: Sanitizar busca para evitar SQL injection
        $busca = trim($busca);
        if (strlen($busca) > 100) {
            $busca = mb_substr($busca, 0, 100);
        }
        
        return $query->where(function($q) use ($busca) {
            $q->where('client_name', 'like', "%{$busca}%")
              ->orWhere('client_email', 'like', "%{$busca}%")
              ->orWhere('client_document', 'like', "%{$busca}%")
              ->orWhere('idTransaction', 'like', "%{$busca}%")
              ->orWhere('user_id', 'like', "%{$busca}%")
              ->orWhereHas('user', function($userQuery) use ($busca) {
                  $userQuery->where('name', 'like', "%{$busca}%")
                           ->orWhere('username', 'like', "%{$busca}%")
                           ->orWhere('email', 'like', "%{$busca}%")
                           ->orWhere('user_id', 'like', "%{$busca}%");
              });
        });
    }

    /**
     * Aplicar filtro de busca em saques
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $busca
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function applyWithdrawalSearch(\Illuminate\Database\Eloquent\Builder $query, string $busca): \Illuminate\Database\Eloquent\Builder
    {
        // SEGURANÇA: Sanitizar busca para evitar SQL injection
        $busca = trim($busca);
        if (strlen($busca) > 100) {
            $busca = mb_substr($busca, 0, 100);
        }
        
        return $query->where(function($q) use ($busca) {
            $q->where('pix_key', 'like', "%{$busca}%")
              ->orWhere('pix_type', 'like', "%{$busca}%")
              ->orWhereHas('user', function($userQuery) use ($busca) {
                  $userQuery->where('name', 'like', "%{$busca}%")
                           ->orWhere('username', 'like', "%{$busca}%");
              });
        });
    }

    /**
     * Aplicar filtro de busca genérico
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $busca
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function applySearchFilter(\Illuminate\Database\Eloquent\Builder $query, string $busca): \Illuminate\Database\Eloquent\Builder
    {
        // SEGURANÇA: Sanitizar busca para evitar SQL injection
        $busca = trim($busca);
        if (strlen($busca) > 100) {
            $busca = mb_substr($busca, 0, 100);
        }
        
        return $query->where(function($q) use ($busca) {
            $q->where('name', 'like', "%{$busca}%")
              ->orWhere('username', 'like', "%{$busca}%")
              ->orWhere('email', 'like', "%{$busca}%")
              ->orWhere('user_id', 'like', "%{$busca}%");
        });
    }

    /**
     * Aplicar ordenação
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $ordenar
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function applySorting(\Illuminate\Database\Eloquent\Builder $query, string $ordenar): \Illuminate\Database\Eloquent\Builder
    {
        match ($ordenar) {
            'saldo_asc' => $query->orderBy('saldo', 'asc'),
            'nome_asc' => $query->orderBy('name', 'asc'),
            default => $query->orderBy('saldo', 'desc'),
        };
        
        return $query;
    }

    /**
     * Formatar transação (depósito ou saque)
     */
    private function formatTransaction($item, string $tipo): array
    {
        $isDeposit = $tipo === 'deposito';
        $model = $isDeposit ? Solicitacoes::class : SolicitacoesCashOut::class;

        return [
            'id' => $item->id,
            'tipo' => $tipo,
            'meio' => $isDeposit ? ($item->method ?? 'pix') : 'pix',
            'cliente_id' => $item->user ? $item->user->username : $item->user_id,
            'transacao_id' => $isDeposit 
                ? $item->idTransaction 
                : ($item->idTransaction ?? 'dep_' . $item->id),
            'valor_total' => (float) $item->amount,
            'valor_liquido' => (float) ($isDeposit ? $item->deposito_liquido : $item->valor_liquido),
            'status' => $item->status,
            'status_legivel' => $this->getStatusLabel($item->status),
            'data' => $item->date,
            'created_at' => $item->created_at,
        ];
    }

    /**
     * Formatar depósito
     */
    private function formatDeposit($item): array
    {
        return [
            'id' => $item->id,
            'meio' => $item->method ?? 'pix',
            'cliente_id' => $item->user ? $item->user->username : $item->user_id,
            'cliente_nome' => $item->client_name,
            'transacao_id' => $item->idTransaction,
            'valor_total' => (float) $item->amount,
            'valor_liquido' => (float) $item->deposito_liquido,
            'taxa' => (float) ($item->amount - $item->deposito_liquido),
            'status' => $item->status,
            'status_legivel' => $this->getStatusLabel($item->status),
            'data' => $item->date,
            'created_at' => $item->created_at,
        ];
    }

    /**
     * Formatar saque
     */
    private function formatWithdrawal($item): array
    {
        return [
            'id' => $item->id,
            'meio' => 'pix',
            'cliente_id' => $item->user ? $item->user->username : $item->user_id,
            'cliente_nome' => $item->user ? $item->user->name : 'N/A',
            'pix_key' => $item->pix_key,
            'pix_type' => $item->pix_type,
            'transacao_id' => $item->idTransaction ?? 'dep_' . $item->id,
            'valor_total' => (float) $item->amount,
            'valor_liquido' => (float) $item->valor_liquido,
            'taxa' => (float) $item->taxa_cash_out,
            'status' => $item->status,
            'status_legivel' => $this->getStatusLabel($item->status),
            'data' => $item->date,
            'created_at' => $item->created_at,
        ];
    }

    /**
     * Formatar carteira
     */
    private function formatWallet($user): array
    {
        return [
            'id' => $user->id,
            'user_id' => $user->user_id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'telefone' => $user->telefone,
            'saldo' => (float) $user->saldo,
            'total_transacoes' => (float) $user->total_transacoes,
            'valor_sacado' => (float) $user->valor_sacado,
            'status' => $user->status == 1 ? 'Aprovado' : 'Pendente',
            'permission' => $user->permission,
            'created_at' => $user->created_at,
        ];
    }

    /**
     * Obter range de datas
     */
    private function getDateRange(string $periodo): array
    {
        $now = Carbon::now();

        return match ($periodo) {
            'hoje' => [
                'inicio' => $now->copy()->startOfDay()->format('Y-m-d H:i:s'),
                'fim' => $now->copy()->endOfDay()->format('Y-m-d H:i:s'),
            ],
            'mes' => [
                'inicio' => $now->copy()->startOfMonth()->format('Y-m-d H:i:s'),
                'fim' => $now->copy()->endOfMonth()->format('Y-m-d H:i:s'),
            ],
            '7d' => [
                'inicio' => $now->copy()->subDays(7)->startOfDay()->format('Y-m-d H:i:s'),
                'fim' => $now->copy()->endOfDay()->format('Y-m-d H:i:s'),
            ],
            '30d' => [
                'inicio' => $now->copy()->subDays(30)->startOfDay()->format('Y-m-d H:i:s'),
                'fim' => $now->copy()->endOfDay()->format('Y-m-d H:i:s'),
            ],
            'total' => [
                'inicio' => '2020-01-01 00:00:00',
                'fim' => $now->copy()->endOfDay()->format('Y-m-d H:i:s'),
            ],
            default => [
                'inicio' => '2020-01-01 00:00:00',
                'fim' => $now->copy()->endOfDay()->format('Y-m-d H:i:s'),
            ],
        };
    }

    /**
     * Obter label do status
     */
    private function getStatusLabel(string $status): string
    {
        return match ($status) {
            'WAITING_FOR_APPROVAL' => 'Aguardando',
            'PAID_OUT' => 'Pago',
            'COMPLETED' => 'Completo',
            'PENDING' => 'Pendente',
            'CANCELLED' => 'Cancelado',
            'REJECTED' => 'Rejeitado',
            'MEDIATION' => 'Mediação',
            default => $status,
        };
    }

    /**
     * Cache key para transações
     */
    private function getTransactionsCacheKey(array $filters): string
    {
        $hash = md5(json_encode($filters));
        return "financial:transactions:{$hash}";
    }

    /**
     * Cache key para carteiras
     */
    private function getWalletsCacheKey(array $filters): string
    {
        $hash = md5(json_encode($filters));
        return "financial:wallets:{$hash}";
    }

    /**
     * Cache key para depósitos
     */
    private function getDepositsCacheKey(array $filters): string
    {
        $hash = md5(json_encode($filters));
        return "financial:deposits:{$hash}";
    }

    /**
     * Cache key para saques
     */
    private function getWithdrawalsCacheKey(array $filters): string
    {
        $hash = md5(json_encode($filters));
        return "financial:withdrawals:{$hash}";
    }

    /**
     * Invalidar cache de carteiras
     * Deve ser chamado quando houver atualização de saldo ou dados de usuário
     */
    public function invalidateWalletsCache(): void
    {
        try {
            // Invalidar cache de estatísticas
            Cache::forget('financial:wallets:stats');
            
            // Nota: Cache de listagem de carteiras será invalidado pelo TTL
            // ou pode ser invalidado manualmente quando necessário
        } catch (\Exception $e) {
            Log::warning('Erro ao invalidar cache de carteiras', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Invalidar cache de estatísticas financeiras
     */
    public function invalidateStatsCache(?string $periodo = null): void
    {
        try {
            if ($periodo) {
                $date = Carbon::now()->format('Ymd');
                Cache::forget("financial:transactions:stats:{$periodo}:{$date}");
                Cache::forget("financial:deposits:stats:{$periodo}:{$date}");
                Cache::forget("financial:withdrawals:stats:{$periodo}:{$date}");
            } else {
                // Invalidar todos os períodos do dia atual
                $date = Carbon::now()->format('Ymd');
                $periodos = ['hoje', 'mes', '7d', '30d', 'total'];
                foreach ($periodos as $p) {
                    Cache::forget("financial:transactions:stats:{$p}:{$date}");
                    Cache::forget("financial:deposits:stats:{$p}:{$date}");
                    Cache::forget("financial:withdrawals:stats:{$p}:{$date}");
                }
            }
        } catch (\Exception $e) {
            Log::warning('Erro ao invalidar cache de estatísticas', [
                'error' => $e->getMessage(),
                'periodo' => $periodo
            ]);
        }
    }

    /**
     * Invalidar cache de depósitos
     * Deve ser chamado quando houver atualização de depósito
     * 
     * Nota: Como não temos cache tags no Redis, invalidamos apenas as estatísticas.
     * O cache de listagem será invalidado automaticamente pelo TTL (60 segundos).
     * Para invalidação mais granular, seria necessário implementar cache tags.
     */
    public function invalidateDepositsCache(): void
    {
        try {
            // Invalidar cache de estatísticas (mais crítico)
            $this->invalidateStatsCache();

            // Nota: Cache de listagem de depósitos usa TTL curto (60s)
            // e será atualizado automaticamente na próxima requisição
        } catch (\Exception $e) {
            Log::warning('Erro ao invalidar cache de depósitos', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Invalidar cache de saques
     * Deve ser chamado quando houver atualização de saque
     */
    public function invalidateWithdrawalsCache(): void
    {
        try {
            // Invalidar cache de listagem (usando padrão de tags se disponível)
            // Como não temos tags, invalidamos apenas as estatísticas
            // O cache de listagem será invalidado pelo TTL
            $this->invalidateStatsCache();
        } catch (\Exception $e) {
            Log::warning('Erro ao invalidar cache de saques', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Atualizar status de depósito
     * 
     * @param int $depositoId ID do depósito
     * @param string $newStatus Novo status
     * @return array Dados do depósito atualizado
     * @throws \Exception Se depósito não encontrado ou status inválido
     */
    public function updateDepositStatus(int $depositoId, string $newStatus): array
    {
        // Buscar depósito com eager loading para evitar N+1
        $deposit = Solicitacoes::with('user:id,user_id,name,username')
            ->find($depositoId);

        if (!$deposit) {
            throw new \Exception('Depósito não encontrado', 404);
        }

        // Salvar status original antes da atualização
        $oldStatus = $deposit->status;

        // Validar transição de status (regras de negócio)
        $this->validateStatusTransition($oldStatus, $newStatus);

        // Atualizar status
        $deposit->update([
            'status' => $newStatus,
            'updated_at' => Carbon::now(),
        ]);

        // Se status mudou para PAID_OUT ou COMPLETED, atualizar saldo do usuário
        if (in_array($newStatus, self::APPROVED_STATUSES) && 
            !in_array($oldStatus, self::APPROVED_STATUSES)) {
            $this->processDepositApproval($deposit);
        }

        // Invalidar cache relacionado
        $this->invalidateDepositsCache();

        // Retornar depósito formatado
        return $this->formatDeposit($deposit->fresh('user'));
    }

    /**
     * Validar transição de status
     * 
     * @param string $currentStatus Status atual
     * @param string $newStatus Novo status
     * @throws \Exception Se transição não permitida
     */
    private function validateStatusTransition(string $currentStatus, string $newStatus): void
    {
        // Se já está no mesmo status, permitir (idempotência)
        if ($currentStatus === $newStatus) {
            return;
        }

        // Regras de transição permitidas
        $allowedTransitions = [
            'PENDING' => ['PAID_OUT', 'COMPLETED', 'CANCELLED', 'REJECTED'],
            'WAITING_FOR_APPROVAL' => ['PAID_OUT', 'COMPLETED', 'CANCELLED', 'REJECTED', 'PENDING'],
            'PAID_OUT' => ['COMPLETED', 'CANCELLED'],
            'COMPLETED' => [], // Status final, não pode mudar
            'CANCELLED' => [], // Status final, não pode mudar
            'REJECTED' => ['PENDING'], // Pode reabrir
        ];

        $allowed = $allowedTransitions[$currentStatus] ?? [];

        if (!in_array($newStatus, $allowed)) {
            throw new \Exception(
                "Transição de status não permitida: {$currentStatus} -> {$newStatus}",
                400
            );
        }
    }

    /**
     * Processar aprovação de depósito
     * Atualiza saldo do usuário quando depósito é aprovado
     * 
     * @param Solicitacoes $deposit
     * @return void
     */
    private function processDepositApproval(Solicitacoes $deposit): void
    {
        try {
            $user = User::where('user_id', $deposit->user_id)->first();

            if (!$user) {
                Log::warning('Usuário não encontrado ao processar aprovação de depósito', [
                    'user_id' => $deposit->user_id,
                    'deposit_id' => $deposit->id,
                ]);
                return;
            }

            // Incrementar saldo do usuário usando Helper (padrão do sistema)
            Helper::incrementAmount($user, $deposit->deposito_liquido, 'saldo');

            // Calcular saldo líquido atualizado
            Helper::calculaSaldoLiquido($user->user_id);

            // Atualizar total de transações
            $user->increment('total_transacoes');

            Log::info('Depósito aprovado e saldo atualizado', [
                'user_id' => $user->user_id,
                'deposit_id' => $deposit->id,
                'amount' => $deposit->deposito_liquido,
                'new_balance' => $user->fresh()->saldo,
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao processar aprovação de depósito', [
                'error' => $e->getMessage(),
                'deposit_id' => $deposit->id,
                'user_id' => $deposit->user_id,
            ]);
            // Não lançar exceção para não reverter a atualização de status
            // O saldo pode ser ajustado manualmente depois
        }
    }
}

