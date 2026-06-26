<?php

namespace App\Services;

use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Serviço de leitura (read-only) do saldo do cliente para consumo externo via API.
 *
 * Replica exatamente os números exibidos no dashboard (UserController::getDashboardStats),
 * para que o integrador veja os mesmos valores do painel. Por ser somente leitura, não
 * interfere no fluxo de cash in / cash out (que é mutado de forma atômica pelo BalanceService).
 */
class ClientBalanceService
{
    private const PAID_STATUSES = Solicitacoes::CONFIRMED_REVENUE_STATUSES;

    /**
     * Retorna o resumo de saldo do usuário (saldo disponível + movimentação do mês corrente).
     *
     * A resposta inteira fica em cache por alguns segundos para suportar polling sem
     * sobrecarregar o banco (consultas leves e cacheadas por usuário).
     *
     * @return array{
     *     moeda: string,
     *     saldo_disponivel: float,
     *     entradas_mes: float,
     *     saidas_mes: float,
     *     fluxo_liquido_mes: float,
     *     periodo: array{inicio: string, fim: string},
     *     atualizado_em: string
     * }
     */
    public function getBalanceSummary(User $user): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $cacheTtl = max(5, (int) config('saldo.balance_cache_ttl_seconds', 10));
        $cacheKey = sprintf('client:balance:full:%s:%s', $user->username, $startOfMonth->format('Ym'));

        return Cache::remember($cacheKey, $cacheTtl, fn () => $this->buildBalanceSummary($user));
    }

    /**
     * @return array{
     *     moeda: string,
     *     saldo_disponivel: float,
     *     saldo_bruto: float,
     *     saldo_em_mediacao: float,
     *     qtd_em_mediacao: int,
     *     entradas_mes: float,
     *     saidas_mes: float,
     *     fluxo_liquido_mes: float,
     *     periodo: array{inicio: string, fim: string},
     *     atualizado_em: string
     * }
     */
    private function buildBalanceSummary(User $user): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $balanceBreakdown = app(BalanceService::class)->getBalanceBreakdown($user);

        $entradasMes = Solicitacoes::forAccount($user)
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->confirmedRevenue()
            ->sum('amount');

        $saidasMes = SolicitacoesCashOut::where(function ($q) use ($user) {
            $q->where('user_id', $user->user_id)->orWhere('user_id', $user->username);
        })
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->whereIn('status', self::PAID_STATUSES)
            ->sum('amount');

        $entradasMes = round((float) $entradasMes, 2);
        $saidasMes = round((float) $saidasMes, 2);

        return [
            'moeda' => 'BRL',
            'saldo_disponivel' => $balanceBreakdown['saldo_disponivel'],
            'saldo_bruto' => $balanceBreakdown['saldo_bruto'],
            'saldo_em_mediacao' => $balanceBreakdown['saldo_em_mediacao'],
            'qtd_em_mediacao' => $balanceBreakdown['qtd_em_mediacao'],
            'entradas_mes' => $entradasMes,
            'saidas_mes' => $saidasMes,
            'fluxo_liquido_mes' => round($entradasMes - $saidasMes, 2),
            'periodo' => [
                'inicio' => $startOfMonth->format('Y-m-d'),
                'fim' => $endOfMonth->format('Y-m-d'),
            ],
            'atualizado_em' => Carbon::now()->toIso8601String(),
        ];
    }
}
