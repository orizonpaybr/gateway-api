<?php

namespace App\Services;

use App\Helpers\WebhookClientMessages;
use App\Jobs\ClientWebhookDispatchJob;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Helpers\Helper;
use App\Services\BalanceService;
use App\Services\ClientWebhookPayloadBuilder;
use App\Services\PaymentProcessingService;
use App\Services\PixAcquirer\PixAcquirerManager;
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
    private const CACHE_TTL_TRANSACTIONS = 60; // 1 minuto (listagens gerais)
    private const CACHE_TTL_STATS = 120; // 2 minutos (stats gerais)
    private const CACHE_TTL_WALLETS = 300; // 5 minutos
    /** TTL curto para telas Financeiro (alinhado ao dashboard admin / extrato) */
    private const CACHE_TTL_TRANSACTIONS_VIEW = 15;
    private const CACHE_TTL_WALLETS_VIEW = 15;
    private const CACHE_TTL_DEPOSITS_VIEW = 15;
    private const CACHE_TTL_WITHDRAWALS_VIEW = 15;

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

        // Cache key baseado nos filtros (TTL curto para atualização rápida na tela Transações Financeiras)
        $cacheKey = $this->getTransactionsCacheKey($filters);

        return Cache::remember($cacheKey, self::CACHE_TTL_TRANSACTIONS_VIEW, function () use ($filters, $tipo) {
            // Saques/depósitos isolados: mesma lógica das telas Relatórios de Saídas/Entradas
            if ($tipo === 'saque') {
                $result = $this->getWithdrawals($filters);
                $result['data'] = array_map(
                    static fn (array $row) => array_merge($row, ['tipo' => 'saque']),
                    $result['data']
                );

                return $result;
            }

            if ($tipo === 'deposito') {
                $result = $this->getDeposits($filters);
                $result['data'] = array_map(
                    static fn (array $row) => array_merge($row, ['tipo' => 'deposito']),
                    $result['data']
                );

                return $result;
            }

            return $this->fetchPaginatedAllTransactions(
                (int) ($filters['page'] ?? 1),
                (int) min($filters['limit'] ?? 20, 100),
                $filters['status'] ?? null,
                null,
                $filters['busca'] ?? null,
                $filters['data_inicio'] ?? null,
                $filters['data_fim'] ?? null
            );
        });
    }

    /**
     * Lista unificada depósitos + saques com paginação no banco (evita carregar tabela inteira em memória).
     */
    private function fetchPaginatedAllTransactions(
        int $page,
        int $limit,
        ?string $status,
        ?string $tipo,
        ?string $busca,
        ?string $dataInicio,
        ?string $dataFim
    ): array {
        $page = max(1, $page);
        $limit = min(max(1, $limit), 100);
        $offset = ($page - 1) * $limit;

        $depositosQuery = $this->buildAdminDepositsUnionQuery($status, $busca, $dataInicio, $dataFim);
        $saquesQuery = $this->buildAdminWithdrawalsUnionQuery($status, $busca, $dataInicio, $dataFim);

        if ($tipo === 'deposito') {
            $unionQuery = $depositosQuery;
        } elseif ($tipo === 'saque') {
            $unionQuery = $saquesQuery;
        } else {
            $unionQuery = $depositosQuery->unionAll($saquesQuery);
        }

        $total = $this->countAllTransactions($status, $tipo, $busca, $dataInicio, $dataFim);

        $rows = DB::query()
            ->fromSub($unionQuery, 'transactions')
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->skip($offset)
            ->take($limit)
            ->get();

        $userLookup = $this->resolveTransactionUserLookup($rows);

        $data = $rows->map(fn ($row) => $this->formatTransactionFromUnionRow($row, $userLookup))->values()->all();

        return [
            'data' => $data,
            'current_page' => $page,
            'last_page' => (int) max(1, (int) ceil($total / $limit)),
            'per_page' => $limit,
            'total' => $total,
        ];
    }

    /**
     * Total de transações (depósitos + saques) para a paginação.
     *
     * Sem filtros (caso padrão da tela): soma duas contagens simples e indexadas,
     * evitando materializar o UNION ALL inteiro só para um COUNT (era a query lenta
     * de ~1s observada em /api/admin/financial/transactions). Com filtros, mantém o
     * COUNT sobre o UNION, mas cacheado por filtro (independente de page/limit).
     */
    private function countAllTransactions(
        ?string $status,
        ?string $tipo,
        ?string $busca,
        ?string $dataInicio,
        ?string $dataFim
    ): int {
        $hasFilters = $status !== null || $busca !== null || $dataInicio !== null || $dataFim !== null;
        $cacheKey = 'fin_tx_count:' . md5(json_encode([$status, $tipo, $busca, $dataInicio, $dataFim]));

        return (int) Cache::remember($cacheKey, 60, function () use ($hasFilters, $status, $tipo, $busca, $dataInicio, $dataFim) {
            if (! $hasFilters) {
                $depCount = $tipo === 'saque' ? 0 : (int) Solicitacoes::count();
                $saqueCount = $tipo === 'deposito' ? 0 : (int) SolicitacoesCashOut::count();

                return $depCount + $saqueCount;
            }

            $depositosQuery = $this->buildAdminDepositsUnionQuery($status, $busca, $dataInicio, $dataFim);
            $saquesQuery = $this->buildAdminWithdrawalsUnionQuery($status, $busca, $dataInicio, $dataFim);

            if ($tipo === 'deposito') {
                $unionQuery = $depositosQuery;
            } elseif ($tipo === 'saque') {
                $unionQuery = $saquesQuery;
            } else {
                $unionQuery = $depositosQuery->unionAll($saquesQuery);
            }

            return (int) DB::query()->fromSub($unionQuery, 'transactions')->count();
        });
    }

    private function buildAdminDepositsUnionQuery(
        ?string $status,
        ?string $busca,
        ?string $dataInicio,
        ?string $dataFim
    ) {
        $query = Solicitacoes::query()
            ->select([
                'id',
                'user_id',
                'idTransaction',
                'amount',
                'deposito_liquido as valor_liquido',
                'status',
                'date',
                'created_at',
                DB::raw("COALESCE(method, 'pix') as meio"),
                DB::raw("'deposito' as tipo"),
            ])
            ->when($status, fn ($q) => $this->applyDepositStatusFilter($q, $status))
            ->when($busca, fn ($q) => $this->applyDepositSearch($q, $busca))
            ->tap(fn ($q) => $this->applyFinancialDateRangeFilter($q, $dataInicio, $dataFim));

        return $query;
    }

    private function buildAdminWithdrawalsUnionQuery(
        ?string $status,
        ?string $busca,
        ?string $dataInicio,
        ?string $dataFim
    ) {
        $query = SolicitacoesCashOut::query()
            ->select([
                'id',
                'user_id',
                'idTransaction',
                'amount',
                'cash_out_liquido as valor_liquido',
                'status',
                'date',
                'created_at',
                DB::raw("'pix' as meio"),
                DB::raw("'saque' as tipo"),
            ])
            ->when($status, fn ($q) => $this->applyWithdrawalStatusFilter($q, $status))
            ->when($busca, fn ($q) => $this->applyWithdrawalSearch($q, $busca))
            ->tap(fn ($q) => $this->applyFinancialDateRangeFilter($q, $dataInicio, $dataFim));

        return $query;
    }

    /**
     * Filtro de período alinhado ao extrato/admin (whereBetween com hora).
     */
    private function applyFinancialDateRangeFilter($query, ?string $dataInicio, ?string $dataFim): void
    {
        if ($dataInicio && $dataFim) {
            $inicio = strlen($dataInicio) === 10 ? $dataInicio . ' 00:00:00' : $dataInicio;
            $fim = strlen($dataFim) === 10 ? $dataFim . ' 23:59:59' : $dataFim;
            $query->whereBetween('date', [$inicio, $fim]);

            return;
        }

        if ($dataInicio) {
            $inicio = strlen($dataInicio) === 10 ? $dataInicio . ' 00:00:00' : $dataInicio;
            $query->where('date', '>=', $inicio);
        }

        if ($dataFim) {
            $fim = strlen($dataFim) === 10 ? $dataFim . ' 23:59:59' : $dataFim;
            $query->where('date', '<=', $fim);
        }
    }

    /**
     * Filtro de status em depósitos (Relatórios de Entradas / transações unificadas).
     * UI "Pendente" envia PENDING, mas PIX IN pendente fica WAITING_FOR_APPROVAL (e variantes).
     */
    private function applyDepositStatusFilter($query, string $status): void
    {
        if ($status === 'PAID_OUT') {
            $query->whereIn('status', ['PAID_OUT', 'COMPLETED']);

            return;
        }

        if ($status === 'PENDING' || $status === 'WAITING_FOR_APPROVAL') {
            $query->whereIn('status', ['WAITING_FOR_APPROVAL', 'PENDING', 'NEW', 'CREATED']);

            return;
        }

        $query->where('status', $status);
    }

    /**
     * Filtro de status em saques (Relatórios de Saídas / transações unificadas).
     * UI "Pago" envia PAID_OUT, mas a maioria dos PIX OUT recentes fica COMPLETED ou PROCESSING.
     */
    private function applyWithdrawalStatusFilter($query, string $status): void
    {
        if ($status === 'PAID_OUT') {
            $query->whereIn('status', ['PAID_OUT', 'COMPLETED', 'PROCESSING']);

            return;
        }

        if ($status === 'FAILED' || $status === 'CANCELLED') {
            $query->where('status', $status);

            return;
        }

        // Transações unificadas (depósitos): pendente usa WAITING_FOR_APPROVAL
        if ($status === 'WAITING_FOR_APPROVAL' || $status === 'PENDING') {
            $query->whereIn('status', ['PENDING']);

            return;
        }

        $query->where('status', $status);
    }

    private function resolveTransactionUserLookup(Collection $rows): array
    {
        $identifiers = $rows->pluck('user_id')->filter()->unique()->values()->all();
        if ($identifiers === []) {
            return ['by_user_id' => [], 'by_username' => []];
        }

        $byUserId = User::query()
            ->select(['user_id', 'username'])
            ->whereIn('user_id', $identifiers)
            ->get()
            ->keyBy('user_id');

        $byUsername = User::query()
            ->select(['user_id', 'username'])
            ->whereIn('username', $identifiers)
            ->get()
            ->keyBy('username');

        return [
            'by_user_id' => $byUserId,
            'by_username' => $byUsername,
        ];
    }

    private function formatTransactionFromUnionRow(object $row, array $userLookup): array
    {
        $tipo = $row->tipo ?? 'deposito';
        $isDeposit = $tipo === 'deposito';
        $status = (string) ($row->status ?? '');

        $valorLiquido = (float) ($row->valor_liquido ?? 0);
        if (! $isDeposit && $valorLiquido == 0.0) {
            $valorLiquido = (float) ($row->amount ?? 0);
        }

        $userId = $row->user_id ?? '';
        $clienteId = $userId;
        if (isset($userLookup['by_user_id'][$userId])) {
            $clienteId = $userLookup['by_user_id'][$userId]->username;
        } elseif (isset($userLookup['by_username'][$userId])) {
            $clienteId = $userLookup['by_username'][$userId]->username;
        }

        $transacaoId = $row->idTransaction ?? null;
        if (! $isDeposit && empty($transacaoId)) {
            $transacaoId = 'dep_' . $row->id;
        }

        return [
            'id' => (int) $row->id,
            'tipo' => $tipo,
            'meio' => $row->meio ?? ($isDeposit ? 'pix' : 'pix'),
            'cliente_id' => $clienteId,
            'transacao_id' => $transacaoId,
            'valor_total' => (float) ($row->amount ?? 0),
            'valor_liquido' => $valorLiquido,
            'status' => $status,
            'status_legivel' => $isDeposit
                ? $this->getStatusLabel($status)
                : $this->getWithdrawalStatusLabel($status),
            'data' => $row->date,
            'created_at' => $row->created_at,
        ];
    }

    /**
     * Obter estatísticas de transações
     */
    public function getTransactionsStats(string $periodo = 'hoje'): array
    {
        $cacheKey = "financial:transactions:stats:{$periodo}:" . Carbon::now()->format('Ymd');

        return Cache::remember($cacheKey, self::CACHE_TTL_TRANSACTIONS_VIEW, function () use ($periodo) {
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

        return Cache::remember($cacheKey, self::CACHE_TTL_WALLETS_VIEW, function () use (
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

        return Cache::remember($cacheKey, self::CACHE_TTL_WALLETS_VIEW, function () {
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

        return Cache::remember($cacheKey, self::CACHE_TTL_DEPOSITS_VIEW, function () use (
            $page, $limit, $status, $busca, $dataInicio, $dataFim
        ) {
            // Select apenas campos necessários para reduzir memória e I/O
            $query = Solicitacoes::with(['user:id,user_id,name,username'])
                ->select([
                    'id', 'user_id', 'idTransaction', 'end_to_end', 'amount', 'deposito_liquido',
                    'status', 'date', 'method', 'client_name', 'created_at',
                    'adquirente_ref', 'executor_ordem',
                ])
                ->when($status, fn ($q) => $this->applyDepositStatusFilter($q, $status))
                ->when($busca, fn($q) => $this->applyDepositSearch($q, $busca))
                ->tap(fn ($q) => $this->applyFinancialDateRangeFilter($q, $dataInicio, $dataFim))
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

        return Cache::remember($cacheKey, self::CACHE_TTL_DEPOSITS_VIEW, function () {
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

        return Cache::remember($cacheKey, self::CACHE_TTL_WITHDRAWALS_VIEW, function () use (
            $page, $limit, $status, $busca, $dataInicio, $dataFim
        ) {
            // Select apenas campos necessários para reduzir memória e I/O
            // CORRIGIDO: Incluir cash_out_liquido e beneficiaryname explicitamente
            $query = SolicitacoesCashOut::with(['user:id,user_id,name,username'])
                ->select([
                    'id',
                    'user_id',
                    'idTransaction',
                    'amount',
                    'cash_out_liquido', // CORRIGIDO: Incluir campo original
                    'status',
                    'date',
                    'pixkey', // CORRIGIDO: Incluir campo original
                    'type', // CORRIGIDO: Incluir campo original
                    'taxa_cash_out',
                    'beneficiaryname', // CORRIGIDO: Incluir para busca
                    'created_at',
                ])
                ->when($status, fn ($q) => $this->applyWithdrawalStatusFilter($q, $status))
                ->when($busca, fn($q) => $this->applyWithdrawalSearch($q, $busca))
                ->tap(fn ($q) => $this->applyFinancialDateRangeFilter($q, $dataInicio, $dataFim))
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
     * Retorna estatísticas gerais (todos os saques), hoje e mês
     * Otimizado: usa uma única query com subqueries para melhor performance
     */
    public function getWithdrawalsStats(string $periodo = 'hoje'): array
    {
        $cacheKey = "financial:withdrawals:stats:{$periodo}:" . Carbon::now()->format('Ymd');

        return Cache::remember($cacheKey, self::CACHE_TTL_WITHDRAWALS_VIEW, function () {
            $now = Carbon::now();
            $hojeInicio = $now->copy()->startOfDay();
            $hojeFim = $now->copy()->endOfDay();
            $mesInicio = $now->copy()->startOfMonth();
            $mesFim = $now->copy()->endOfMonth();

            $custoExpr = \App\Helpers\CustoAdquirentePixHelper::sqlCustoPorTransacaoExpr('amount', true);

            // Saques: custo por transação conforme executor_ordem (custo fixo por adquirente)
            $custoSql = "COALESCE(SUM({$custoExpr}), 0)";
            $stats = DB::selectOne("
                SELECT 
                    -- Estatísticas gerais
                    (SELECT COUNT(*) FROM solicitacoes_cash_out) as total_saques_geral,
                    (SELECT COUNT(*) FROM solicitacoes_cash_out WHERE status IN (?, ?)) as saques_aprovados_geral,
                    (SELECT COALESCE(SUM(amount), 0) FROM solicitacoes_cash_out WHERE status IN (?, ?)) as valor_total_geral,
                    (SELECT COALESCE(SUM(taxa_cash_out), 0) - {$custoSql} FROM solicitacoes_cash_out WHERE status IN (?, ?)) as lucro_total_geral,
                    -- Estatísticas de hoje
                    (SELECT COUNT(*) FROM solicitacoes_cash_out WHERE date BETWEEN ? AND ? AND status IN (?, ?)) as saques_aprovados_hoje,
                    (SELECT COALESCE(SUM(amount), 0) FROM solicitacoes_cash_out WHERE date BETWEEN ? AND ? AND status IN (?, ?)) as valor_total_hoje,
                    (SELECT COALESCE(SUM(taxa_cash_out), 0) - {$custoSql} FROM solicitacoes_cash_out WHERE date BETWEEN ? AND ? AND status IN (?, ?)) as lucro_total_hoje,
                    -- Estatísticas do mês
                    (SELECT COUNT(*) FROM solicitacoes_cash_out WHERE date BETWEEN ? AND ? AND status IN (?, ?)) as saques_aprovados_mes,
                    (SELECT COALESCE(SUM(amount), 0) FROM solicitacoes_cash_out WHERE date BETWEEN ? AND ? AND status IN (?, ?)) as valor_total_mes,
                    (SELECT COALESCE(SUM(taxa_cash_out), 0) - {$custoSql} FROM solicitacoes_cash_out WHERE date BETWEEN ? AND ? AND status IN (?, ?)) as lucro_total_mes,
                    -- Saques pendentes (geral)
                    (SELECT COUNT(*) FROM solicitacoes_cash_out WHERE status = ?) as saques_pendentes_geral
            ", array_merge(
                self::APPROVED_STATUSES, // saques_aprovados_geral
                self::APPROVED_STATUSES, // valor_total_geral
                self::APPROVED_STATUSES, // lucro_total_geral
                [$hojeInicio, $hojeFim], // saques_aprovados_hoje
                self::APPROVED_STATUSES,
                [$hojeInicio, $hojeFim], // valor_total_hoje
                self::APPROVED_STATUSES,
                [$hojeInicio, $hojeFim], // lucro_total_hoje
                self::APPROVED_STATUSES,
                [$mesInicio, $mesFim], // saques_aprovados_mes
                self::APPROVED_STATUSES,
                [$mesInicio, $mesFim], // valor_total_mes
                self::APPROVED_STATUSES,
                [$mesInicio, $mesFim], // lucro_total_mes
                self::APPROVED_STATUSES,
                ['PENDING'] // saques_pendentes_geral
            ));

            return [
                // Estatísticas gerais
                'total_saques_geral' => (int) ($stats->total_saques_geral ?? 0),
                'saques_aprovados_geral' => (int) ($stats->saques_aprovados_geral ?? 0),
                'valor_total_geral' => (float) ($stats->valor_total_geral ?? 0),
                'lucro_total_geral' => (float) ($stats->lucro_total_geral ?? 0),
                // Estatísticas de hoje
                'saques_aprovados_hoje' => (int) ($stats->saques_aprovados_hoje ?? 0),
                'valor_total_hoje' => (float) ($stats->valor_total_hoje ?? 0),
                'lucro_total_hoje' => (float) ($stats->lucro_total_hoje ?? 0),
                // Estatísticas do mês
                'saques_aprovados_mes' => (int) ($stats->saques_aprovados_mes ?? 0),
                'valor_total_mes' => (float) ($stats->valor_total_mes ?? 0),
                'lucro_total_mes' => (float) ($stats->lucro_total_mes ?? 0),
                // Pendentes
                'saques_pendentes_geral' => (int) ($stats->saques_pendentes_geral ?? 0),
                
                // Mantém compatibilidade com código antigo
                'total_saques' => (int) ($stats->total_saques_geral ?? 0),
                'saques_aprovados' => (int) ($stats->saques_aprovados_geral ?? 0),
                'saques_pendentes' => (int) ($stats->saques_pendentes_geral ?? 0),
                'valor_total' => (float) ($stats->valor_total_geral ?? 0),
                'lucro_saques' => (float) ($stats->lucro_total_geral ?? 0),
            ];
        });
    }

    // ========== Métodos Privados (Helpers) ==========

    /**
     * Estatísticas agregadas de depósitos
     */
    private function getDepositsStatsAggregated(array $dateRange): array
    {
        $custoTreeal = (float) config('treeal.custo_fixo_transacao', 0.05);
        $custoSimpay = \App\Helpers\CustoAdquirentePixHelper::CUSTO_HISTORICO_SIMPAY;
        $custoFyhub = (float) config('fyhub.custo_fixo_transacao', 0.04);
        $custoFluxpayments = (float) config('fluxpayments.custo_fixo_transacao', 0.09);
        $custoPaya55 = (float) config('paya55.custo_fixo_transacao', 0.03);

        $stats = Solicitacoes::whereBetween('date', [$dateRange['inicio'], $dateRange['fim']])
            ->selectRaw('
                SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as aprovadas,
                SUM(CASE WHEN status IN (?, ?) THEN (
                    taxa_cash_in -
                    CASE
                        WHEN taxa_pix_cash_in_adquirente IS NOT NULL AND taxa_pix_cash_in_adquirente > 0
                        THEN taxa_pix_cash_in_adquirente
                        WHEN executor_ordem = \'treeal\' THEN ' . $custoTreeal . '
                        WHEN executor_ordem = \'fyhub\' THEN ' . $custoFyhub . '
                        WHEN executor_ordem = \'fluxpayments\' THEN ' . $custoFluxpayments . '
                        WHEN executor_ordem = \'paya55\' THEN ' . $custoPaya55 . '
                        WHEN executor_ordem = \'simpay\' OR executor_ordem = \'Adquirente PIX\' OR adquirente_ref = \'Adquirente PIX\'
                        THEN ' . $custoSimpay . '
                        ELSE 0
                    END
                ) ELSE 0 END) as lucro
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
        $custoExpr = \App\Helpers\CustoAdquirentePixHelper::sqlCustoPorTransacaoExpr('amount', true);

        $stats = SolicitacoesCashOut::whereBetween('date', [$dateRange['inicio'], $dateRange['fim']])
            ->selectRaw('
                SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as aprovadas,
                SUM(CASE WHEN status IN (?, ?) THEN taxa_cash_out ELSE 0 END) as taxa_total
            ', array_merge(
                self::APPROVED_STATUSES,
                self::APPROVED_STATUSES
            ))
            ->first();

        $totalSaques = (int) ($stats->aprovadas ?? 0);
        $taxaTotal = (float) ($stats->taxa_total ?? 0);

        $custoAdquirente = (float) SolicitacoesCashOut::whereBetween('date', [$dateRange['inicio'], $dateRange['fim']])
            ->whereIn('status', self::APPROVED_STATUSES)
            ->sum(DB::raw($custoExpr));

        $lucro = $taxaTotal - $custoAdquirente;

        return [
            'aprovadas' => $totalSaques,
            'lucro' => $lucro,
        ];
    }

    /**
     * Calcular lucro para período específico
     */
    private function calculateProfit(string $periodo): float
    {
        $dateRange = $this->getDateRange($periodo);
        $custoTreeal = (float) config('treeal.custo_fixo_transacao', 0.05);
        $custoSimpay = \App\Helpers\CustoAdquirentePixHelper::CUSTO_HISTORICO_SIMPAY;
        $custoFyhub = (float) config('fyhub.custo_fixo_transacao', 0.04);
        $custoFluxpayments = (float) config('fluxpayments.custo_fixo_transacao', 0.09);
        $custoPaya55 = (float) config('paya55.custo_fixo_transacao', 0.03);
        $custoSaqueExpr = \App\Helpers\CustoAdquirentePixHelper::sqlCustoPorTransacaoExpr('amount', true);

        // Lucro líquido de depósitos: taxa_cash_in − custo por adquirente quando não há taxa explícita na linha
        $lucroDepositos = Solicitacoes::whereIn('status', self::APPROVED_STATUSES)
            ->whereBetween('date', [$dateRange['inicio'], $dateRange['fim']])
            ->sum(DB::raw("taxa_cash_in -
                CASE
                    WHEN taxa_pix_cash_in_adquirente IS NOT NULL AND taxa_pix_cash_in_adquirente > 0
                    THEN taxa_pix_cash_in_adquirente
                    WHEN executor_ordem = 'treeal' THEN {$custoTreeal}
                    WHEN executor_ordem = 'fyhub' THEN {$custoFyhub}
                    WHEN executor_ordem = 'fluxpayments' THEN {$custoFluxpayments}
                    WHEN executor_ordem = 'paya55' THEN {$custoPaya55}
                    WHEN executor_ordem = 'simpay' OR executor_ordem = 'Adquirente PIX' OR adquirente_ref = 'Adquirente PIX'
                    THEN {$custoSimpay}
                    ELSE 0
                END"));

        $taxaTotalSaques = SolicitacoesCashOut::whereIn('status', self::APPROVED_STATUSES)
            ->whereBetween('date', [$dateRange['inicio'], $dateRange['fim']])
            ->sum('taxa_cash_out');

        $custoAdquirenteSaques = (float) SolicitacoesCashOut::whereIn('status', self::APPROVED_STATUSES)
            ->whereBetween('date', [$dateRange['inicio'], $dateRange['fim']])
            ->sum(DB::raw($custoSaqueExpr));

        $lucroSaques = $taxaTotalSaques - $custoAdquirenteSaques;

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
     * CORRIGIDO: Incluir busca por user_id, beneficiaryname e pixkey diretamente
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
            $q->where('user_id', 'like', "%{$busca}%")
              ->orWhere('beneficiaryname', 'like', "%{$busca}%")
              ->orWhere('pixkey', 'like', "%{$busca}%")
              ->orWhere('type', 'like', "%{$busca}%")
              ->orWhere('idTransaction', 'like', "%{$busca}%")
              ->orWhereHas('user', function($userQuery) use ($busca) {
                  $userQuery->where('name', 'like', "%{$busca}%")
                           ->orWhere('username', 'like', "%{$busca}%")
                           ->orWhere('email', 'like', "%{$busca}%")
                           ->orWhere('user_id', 'like', "%{$busca}%");
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
            'adquirente_ref' => $item->adquirente_ref,
            'executor_ordem' => $item->executor_ordem,
            'pode_estornar' => $this->depositPodeEstornar($item),
        ];
    }

    /**
     * Estorno PIX (Fyhub/Treeal/FluxPayments/Paya55) permitido na UI admin.
     *
     * Depósitos da adquirente descontinuada (simpay) não estornam:
     * não há mais integração para chamar.
     */
    private function depositPodeEstornar(Solicitacoes $item): bool
    {
        // executor_ordem = família do provider (fixo por serviço); adquirente_ref
        // pode variar por nominal (várias contas FluxPayments), então o gate de
        // "provider suportado" precisa usar executor_ordem, não adquirente_ref.
        $provider = strtolower(trim((string) ($item->executor_ordem ?? '')));
        if (! in_array($provider, ['fyhub', 'treeal', 'fluxpayments', 'paya55'], true)) {
            return false;
        }

        $st = strtoupper((string) ($item->status ?? ''));
        if (! in_array($st, ['PAID_OUT', 'COMPLETED'], true)) {
            return false;
        }

        if (in_array($provider, ['fyhub', 'treeal'], true)) {
            return trim((string) ($item->end_to_end ?? '')) !== '';
        }

        // FluxPayments / Paya55: idTransaction
        return trim((string) ($item->idTransaction ?? '')) !== '';
    }

    /**
     * Solicita estorno na adquirente (Fyhub/Treeal/FluxPayments/Paya55) e atualiza depósito/saldo localmente.
     *
     * @throws \Exception com código HTTP em $e->getCode() quando aplicável
     */
    public function refundDeposit(int $depositoId, string $reason = ''): array
    {
        $reason = trim($reason) !== ''
            ? trim($reason)
            : 'Estorno solicitado pelo painel administrativo';

        $deposit = Solicitacoes::with('user:id,user_id,name,username')
            ->find($depositoId);

        if (! $deposit) {
            throw new \Exception('Depósito não encontrado', 404);
        }

        // executor_ordem = família do provider (fixo por serviço); adquirente_ref
        // pode variar por nominal (várias contas FluxPayments).
        $provider = strtolower(trim((string) ($deposit->executor_ordem ?? '')));
        if (! in_array($provider, ['fyhub', 'treeal', 'fluxpayments', 'paya55'], true)) {
            throw new \Exception('Estorno disponível apenas para depósitos Fyhub/Treeal/FluxPayments/Paya55.', 422);
        }

        $st = strtoupper((string) $deposit->status);
        if ($st === 'REFUNDED') {
            throw new \Exception('Depósito já estornado.', 400);
        }
        if (! in_array($st, ['PAID_OUT', 'COMPLETED'], true)) {
            throw new \Exception('Apenas depósitos pagos podem ser estornados.', 422);
        }

        $tid = trim((string) ($deposit->idTransaction ?? ''));
        if (in_array($provider, ['fyhub', 'treeal'], true)) {
            $tid = trim((string) ($deposit->end_to_end ?? ''));
        }
        if ($tid === '') {
            throw new \Exception(
                in_array($provider, ['fyhub', 'treeal'], true)
                    ? 'Depósito '.strtoupper($provider).' sem endToEndId para devolução.'
                    : 'Transação sem identificador na adquirente.',
                422
            );
        }

        // adquirente_ref carrega a nominal específica (credenciais próprias);
        // cai pro provider quando não houver (depósitos antigos, single-nominal).
        $nominal = strtolower(trim((string) ($deposit->adquirente_ref ?? '')));
        $manager = app(PixAcquirerManager::class);
        $acquirer = $manager->resolve($nominal !== '' ? $nominal : $provider);
        if (! $acquirer->isActive()) {
            throw new \Exception('Integração '.$provider.' indisponível.', 503);
        }

        $refundResult = $acquirer->createRefund($tid, (float) $deposit->amount, $reason);
        if (! ($refundResult['success'] ?? false)) {
            $msg = is_string($refundResult['message'] ?? null)
                ? (string) $refundResult['message']
                : 'Falha ao solicitar estorno na adquirente.';

            throw new \Exception($msg, 502);
        }

        DB::transaction(function () use ($deposit) {
            $locked = Solicitacoes::where('id', $deposit->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new \Exception('Depósito não encontrado após resposta da adquirente.', 500);
            }

            $cur = strtoupper((string) $locked->status);
            if ($cur === 'REFUNDED') {
                throw new \Exception('Depósito já estornado.', 409);
            }
            if (! in_array($cur, ['PAID_OUT', 'COMPLETED'], true)) {
                throw new \Exception('Status do depósito não permite estorno.', 409);
            }

            $locked->update([
                'status' => 'REFUNDED',
                'updated_at' => Carbon::now(),
            ]);

            $user = User::where('user_id', $locked->user_id)->lockForUpdate()->first();
            if ($user) {
                app(BalanceService::class)->decrementBalanceForRefund(
                    $user,
                    (float) $locked->deposito_liquido,
                    'saldo'
                );

                try {
                    app(\App\Services\AffiliateCommissionService::class)
                        ->reverseCashInCommissionForRefundedDeposit($locked);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[FINANCIAL] Falha ao estornar comissão de afiliado no estorno', [
                        'deposit_id' => $locked->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Helper::calculaSaldoLiquido($locked->user_id);
        });

        $depositFresh = Solicitacoes::with('user:id,user_id,name,username')->find($depositoId);

        if (! $depositFresh) {
            throw new \Exception('Erro ao recarregar depósito após estorno.', 500);
        }

        app(PaymentProcessingService::class)->invalidateCachesAfterPayment($depositFresh->user_id);

        $this->invalidateDepositsCache();

        $this->dispatchDepositRefundWebhookToClient($depositFresh);

        return $this->formatDeposit($depositFresh);
    }

    /**
     * Notifica o integrador (postback) quando o estorno foi concluído pelo painel admin.
     */
    private function dispatchDepositRefundWebhookToClient(Solicitacoes $deposit): void
    {
        $callback = trim((string) ($deposit->callback ?? ''));
        if ($callback === '' || $callback === 'web') {
            return;
        }

        try {
            ClientWebhookDispatchJob::send(
                $callback,
                (string) $deposit->idTransaction,
                'REFUNDED',
                (float) $deposit->amount,
                now()->toIso8601String(),
                ClientWebhookPayloadBuilder::extraForDeposit($deposit),
                WebhookClientMessages::getMessageForStatus('REFUNDED', 'PIX_IN')
            );
        } catch (\Throwable $e) {
            Log::warning('FinancialService: falha ao enfileirar webhook de estorno ao cliente', [
                'deposit_id' => $deposit->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Formatar saque
     * CORRIGIDO: Usar cash_out_liquido diretamente e incluir beneficiaryname
     * CORRIGIDO: Calcular cash_out_liquido se NULL (para saques antigos)
     */
    private function formatWithdrawal($item): array
    {
        // CORRIGIDO: Usar cash_out_liquido diretamente ao invés de valor_liquido
        // Se cash_out_liquido for NULL ou 0, calcular: amount (cliente sempre recebe o valor solicitado)
        $valorLiquido = $item->cash_out_liquido ?? $item->valor_liquido ?? null;
        if ($valorLiquido === null || $valorLiquido == 0) {
            // Para saques, o cliente sempre recebe o valor solicitado (amount)
            // A taxa é descontada do saldo, não do valor recebido
            $valorLiquido = $item->amount ?? 0;
        }
        
        // CORRIGIDO: Usar pixkey e type diretamente ao invés de pix_key e pix_type
        $pixKey = $item->pixkey ?? $item->pix_key ?? '';
        $pixType = $item->type ?? $item->pix_type ?? '';
        
        // CORRIGIDO: Usar beneficiaryname se disponível, senão usar nome do usuário
        $clienteNome = $item->beneficiaryname ?? ($item->user ? $item->user->name : 'N/A');

        return [
            'id' => $item->id,
            'meio' => 'pix',
            'cliente_id' => $item->user ? $item->user->username : $item->user_id,
            'cliente_nome' => $clienteNome,
            'pix_key' => $pixKey,
            'pix_type' => $pixType,
            'transacao_id' => $item->idTransaction ?? 'dep_' . $item->id,
            'valor_total' => (float) $item->amount,
            'valor_liquido' => (float) $valorLiquido,
            'taxa' => (float) $item->taxa_cash_out,
            'status' => $item->status,
            'status_legivel' => $this->getWithdrawalStatusLabel($item->status),
            'data' => $item->date,
            'created_at' => $item->created_at,
        ];
    }

    /**
     * Label de status para saques (PIX OUT).
     * PROCESSING na adquirente = PIX já enviado; exibir como "Concluído".
     */
    private function getWithdrawalStatusLabel(?string $status): string
    {
        $status = (string) ($status ?? '');
        if ($status === 'PROCESSING') {
            return 'Concluído';
        }
        return $this->getStatusLabel($status);
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
    private function getStatusLabel(?string $status): string
    {
        $s = strtoupper(trim((string) ($status ?? '')));

        return match ($s) {
            'WAITING_FOR_APPROVAL' => 'Pendente',
            'PAID_OUT' => 'Pago',
            'COMPLETED' => 'Completo',
            'REFUNDED' => 'Estornado',
            'PENDING' => 'Pendente',
            'CANCELLED' => 'Cancelado',
            'REJECTED' => 'Rejeitado',
            'FAILED' => 'Falhou',
            'MEDIATION' => 'Mediação',
            'NEW', 'CREATED' => 'Pendente',
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
            DB::transaction(function () use ($deposit) {
                // Lock da linha: serializa contra o webhook (PaymentProcessingService) rodando ao mesmo tempo.
                $locked = Solicitacoes::where('id', $deposit->id)->lockForUpdate()->first();
                if (! $locked) {
                    return;
                }

                // Idempotência COMPARTILHADA com o webhook: se já existe evento de crédito, não credita de novo.
                // Antes este caminho creditava sem gravar evento — o webhook não via e recreditava (duplo-crédito).
                $jaCreditado = \App\Models\PaymentEvent::where('transaction_id', $locked->id)
                    ->where('event_type', 'PAYMENT_RECEIVED')
                    ->where('transaction_type', 'deposit')
                    ->exists();
                if ($jaCreditado) {
                    Log::info('Depósito já creditado (evento existente) — aprovação admin não recredita', [
                        'deposit_id' => $locked->id,
                        'user_id' => $locked->user_id,
                    ]);
                    return;
                }

                $user = User::where('user_id', $locked->user_id)->lockForUpdate()->first();
                if (! $user) {
                    Log::warning('Usuário não encontrado ao processar aprovação de depósito', [
                        'user_id' => $locked->user_id,
                        'deposit_id' => $locked->id,
                    ]);
                    return;
                }

                $balanceBefore = (float) $user->saldo;

                // Incrementar saldo do usuário (com trilha no balance_ledger_entries)
                app(\App\Services\BalanceService::class)->incrementBalance(
                    $user,
                    (float) $locked->deposito_liquido,
                    'saldo',
                    [
                        'reason' => 'deposit_credit',
                        'source' => 'FinancialService::aprovarDeposito',
                        'ref_type' => 'solicitacoes',
                        'ref_id' => $locked->id,
                    ]
                );

                $balanceAfter = (float) $user->fresh()->saldo;

                // Grava o evento: é ele que impede o webhook de recreditar depois desta aprovação manual.
                app(\App\Services\PaymentEventService::class)
                    ->recordPaymentReceived($locked, $user, $balanceBefore, $balanceAfter);

                // Calcular saldo líquido atualizado
                Helper::calculaSaldoLiquido($user->user_id);

                // Atualizar total de transações (evita acesso a método protegido em alguns contextos)
                $user->total_transacoes = ($user->total_transacoes ?? 0) + 1;
                $user->save();

                Log::info('Depósito aprovado e saldo atualizado', [
                    'user_id' => $user->user_id,
                    'deposit_id' => $locked->id,
                    'amount' => $locked->deposito_liquido,
                    'new_balance' => $balanceAfter,
                ]);
            });
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

