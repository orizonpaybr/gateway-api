<?php

namespace App\Services;

use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Support\Collection;
use Carbon\Carbon;

/**
 * Service para lógica de negócio financeira
 * 
 * Implementa:
 * - Cache Redis para performance
 * - Queries otimizadas
 * - DRY (Don't Repeat Yourself)
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
     */
    public function getWallets(array $filters): array
    {
        $page = $filters['page'] ?? 1;
        $limit = min($filters['limit'] ?? 20, 100);
        $busca = $filters['busca'] ?? null;
        $tipoUsuario = $filters['tipo_usuario'] ?? null;
        $ordenar = $filters['ordenar'] ?? 'saldo_desc';

        $cacheKey = $this->getWalletsCacheKey($filters);

        return Cache::remember($cacheKey, self::CACHE_TTL_WALLETS, function () use (
            $page, $limit, $busca, $tipoUsuario, $ordenar
        ) {
            $query = User::query()
                ->select([
                    'id', 'user_id', 'name', 'username', 'email',
                    'saldo', 'total_transacoes', 'valor_sacado',
                    'status', 'permission', 'created_at',
                ])
                ->when($busca, fn($q) => $this->applySearchFilter($q, $busca))
                ->when($tipoUsuario === 'ativo', fn($q) => $q->where('saldo', '>', 0))
                ->when($tipoUsuario === 'inativo', fn($q) => $q->where('saldo', '<=', 0));

            // Aplicar ordenação
            $this->applySorting($query, $ordenar);

            $wallets = $query->paginate($limit, ['*'], 'page', $page);

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
     */
    public function getWalletsStats(): array
    {
        $cacheKey = 'financial:wallets:stats';

        return Cache::remember($cacheKey, self::CACHE_TTL_STATS, function () {
            // Usar agregados para melhor performance
            $stats = User::selectRaw('
                COUNT(*) as total_carteiras,
                SUM(saldo) as saldo_total,
                SUM(CASE WHEN saldo > 0 THEN 1 ELSE 0 END) as carteiras_ativas,
                AVG(saldo) as valor_medio_carteira
            ')->first();

            return [
                'total_carteiras' => (int) $stats->total_carteiras,
                'saldo_total' => (float) ($stats->saldo_total ?? 0),
                'carteiras_ativas' => (int) $stats->carteiras_ativas,
                'valor_medio_carteira' => (float) ($stats->valor_medio_carteira ?? 0),
            ];
        });
    }

    /**
     * Obter depósitos (entradas)
     */
    public function getDeposits(array $filters): array
    {
        $page = $filters['page'] ?? 1;
        $limit = min($filters['limit'] ?? 20, 100);
        $status = $filters['status'] ?? null;
        $busca = $filters['busca'] ?? null;
        $dataInicio = $filters['data_inicio'] ?? null;
        $dataFim = $filters['data_fim'] ?? null;

        $query = Solicitacoes::with('user:id,user_id,name,username')
            ->when($status, fn($q) => $q->where('status', $status))
            ->when($busca, fn($q) => $this->applyDepositSearch($q, $busca))
            ->when($dataInicio, fn($q) => $q->where('date', '>=', $dataInicio))
            ->when($dataFim, fn($q) => $q->where('date', '<=', $dataFim . ' 23:59:59'))
            ->orderBy('date', 'desc');

        $deposits = $query->paginate($limit, ['*'], 'page', $page);

        $depositsData = $deposits->getCollection()->map(fn($item) => $this->formatDeposit($item));

        return [
            'data' => $depositsData->toArray(),
            'current_page' => $deposits->currentPage(),
            'last_page' => $deposits->lastPage(),
            'per_page' => $deposits->perPage(),
            'total' => $deposits->total(),
        ];
    }

    /**
     * Obter estatísticas de depósitos
     */
    public function getDepositsStats(string $periodo = 'hoje'): array
    {
        $cacheKey = "financial:deposits:stats:{$periodo}:" . Carbon::now()->format('Ymd');

        return Cache::remember($cacheKey, self::CACHE_TTL_STATS, function () use ($periodo) {
            $dateRange = $this->getDateRange($periodo);

            $stats = Solicitacoes::whereBetween('date', [$dateRange['inicio'], $dateRange['fim']])
                ->selectRaw('
                    COUNT(*) as total_depositos,
                    SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as depositos_aprovados,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as depositos_pendentes,
                    SUM(CASE WHEN status IN (?, ?) THEN amount ELSE 0 END) as valor_total,
                    SUM(CASE WHEN status IN (?, ?) THEN (amount - deposito_liquido) ELSE 0 END) as lucro_depositos
                ', array_merge(
                    self::APPROVED_STATUSES,
                    ['WAITING_FOR_APPROVAL'],
                    self::APPROVED_STATUSES,
                    self::APPROVED_STATUSES
                ))
                ->first();

            return [
                'total_depositos' => (int) ($stats->total_depositos ?? 0),
                'depositos_aprovados' => (int) ($stats->depositos_aprovados ?? 0),
                'depositos_pendentes' => (int) ($stats->depositos_pendentes ?? 0),
                'valor_total' => (float) ($stats->valor_total ?? 0),
                'lucro_depositos' => (float) ($stats->lucro_depositos ?? 0),
            ];
        });
    }

    /**
     * Obter saques (saídas)
     */
    public function getWithdrawals(array $filters): array
    {
        $page = $filters['page'] ?? 1;
        $limit = min($filters['limit'] ?? 20, 100);
        $status = $filters['status'] ?? null;
        $busca = $filters['busca'] ?? null;
        $dataInicio = $filters['data_inicio'] ?? null;
        $dataFim = $filters['data_fim'] ?? null;

        $query = SolicitacoesCashOut::with('user:id,user_id,name,username')
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
     */
    private function applyDepositSearch($query, string $busca)
    {
        return $query->where(function($q) use ($busca) {
            $q->where('client_name', 'like', "%{$busca}%")
              ->orWhere('client_email', 'like', "%{$busca}%")
              ->orWhere('client_document', 'like', "%{$busca}%")
              ->orWhere('idTransaction', 'like', "%{$busca}%");
        });
    }

    /**
     * Aplicar filtro de busca em saques
     */
    private function applyWithdrawalSearch($query, string $busca)
    {
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
     */
    private function applySearchFilter($query, string $busca)
    {
        return $query->where(function($q) use ($busca) {
            $q->where('name', 'like', "%{$busca}%")
              ->orWhere('username', 'like', "%{$busca}%")
              ->orWhere('email', 'like', "%{$busca}%")
              ->orWhere('user_id', 'like', "%{$busca}%");
        });
    }

    /**
     * Aplicar ordenação
     */
    private function applySorting($query, string $ordenar)
    {
        match ($ordenar) {
            'saldo_asc' => $query->orderBy('saldo', 'asc'),
            'nome_asc' => $query->orderBy('name', 'asc'),
            default => $query->orderBy('saldo', 'desc'),
        };
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
}

