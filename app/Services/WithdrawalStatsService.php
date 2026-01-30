<?php

namespace App\Services;

use App\Models\SolicitacoesCashOut;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;

class WithdrawalStatsService
{
    public function calculate(string $periodo = 'hoje'): array
    {
        [$inicio, $fim] = $this->resolvePeriod($periodo);

        $cacheKey = sprintf('withdrawals_stats:%s:%s:%s', $periodo, $inicio->format('Y-m-d'), $fim->format('Y-m-d'));

        return Cache::remember($cacheKey, 60, function () use ($inicio, $fim) {
            // CORRIGIDO: Incluir todos os tipos de saques (WEB, MANUAL, AUTOMATICO) nas estatísticas
            $base = SolicitacoesCashOut::whereIn('descricao_transacao', ['WEB', 'MANUAL', 'AUTOMATICO'])
                ->whereBetween('date', [$inicio, $fim]);

            return [
                'periodo' => [
                    'inicio' => $inicio->format('Y-m-d'),
                    'fim' => $fim->format('Y-m-d'),
                ],
                'totais' => [
                    'pendentes' => (clone $base)->where('status', 'PENDING')->count(),
                    'aprovados' => (clone $base)->where('status', 'COMPLETED')->count(),
                    'rejeitados' => (clone $base)->where('status', 'CANCELLED')->count(),
                ],
                'valores' => [
                    'total' => (clone $base)->sum('amount'),
                    'aprovado' => (clone $base)->where('status', 'COMPLETED')->sum('amount'),
                ],
                'tipos' => [
                    'manuais' => (clone $base)->whereNull('executor_ordem')->count(),
                    'automaticos' => (clone $base)->whereNotNull('executor_ordem')->count(),
                ],
            ];
        });
    }

    private function resolvePeriod(string $periodo): array
    {
        $inicio = match ($periodo) {
            'hoje' => now()->startOfDay(),
            '7d' => now()->subDays(7)->startOfDay(),
            '30d' => now()->subDays(30)->startOfDay(),
            'mes' => now()->startOfMonth(),
            default => now()->startOfDay(),
        };

        return [$inicio, now()];
    }
}


