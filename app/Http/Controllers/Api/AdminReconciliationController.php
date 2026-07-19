<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{User, Solicitacoes, SolicitacoesCashOut};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Log};
use Carbon\Carbon;

/**
 * Relatório de conciliação diária por usuário (Admin).
 *
 * Para cada usuário/dia com movimentação:
 *   saldo_final = saldo_inicial + depósitos_líquidos - saques_debitados
 *
 * O saldo é reconstruído de trás pra frente a partir do saldo atual da
 * carteira (users.saldo + saldo_afiliado), descontando/somando os movimentos
 * ocorridos após o fim do período. Ajustes manuais de saldo e estornos fora
 * do fluxo padrão podem gerar pequenas divergências (saldo é estimado).
 */
class AdminReconciliationController extends Controller
{
    private const MAX_PERIOD_DAYS = 31;

    private const WITHDRAW_CONFIRMED_STATUSES = ['COMPLETED', 'PAID_OUT'];

    /**
     * GET /admin/reports/reconciliation?periodo=hoje|ontem|7dias|30dias|YYYY-MM-DD:YYYY-MM-DD&user_id=
     */
    public function getReport(Request $request)
    {
        try {
            [$dataInicio, $dataFim] = $this->resolvePeriod($request->input('periodo', 'hoje'));

            if ($dataInicio->diffInDays($dataFim) > self::MAX_PERIOD_DAYS) {
                return $this->errorResponse('Período máximo do relatório é de ' . self::MAX_PERIOD_DAYS . ' dias', 422);
            }

            $report = $this->buildReport($dataInicio, $dataFim, $request->input('user_id'));

            return $this->successResponse($report);
        } catch (\Exception $e) {
            Log::error('Erro ao gerar relatório de conciliação', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Erro ao gerar relatório de conciliação', 500);
        }
    }

    /**
     * GET /admin/reports/reconciliation/export — CSV compatível com Excel (pt-BR).
     */
    public function exportReport(Request $request)
    {
        try {
            [$dataInicio, $dataFim] = $this->resolvePeriod($request->input('periodo', 'hoje'));

            if ($dataInicio->diffInDays($dataFim) > self::MAX_PERIOD_DAYS) {
                return $this->errorResponse('Período máximo do relatório é de ' . self::MAX_PERIOD_DAYS . ' dias', 422);
            }

            $report = $this->buildReport($dataInicio, $dataFim, $request->input('user_id'));

            // Ex.: coratri_2026_07_19.csv (dia atual) ou coratri_2026_07_01_a_2026_07_19.csv
            $hoje = Carbon::today()->format('Y_m_d');
            $inicioFmt = $dataInicio->format('Y_m_d');
            $fimFmt = $dataFim->format('Y_m_d');
            $filename = $inicioFmt === $fimFmt
                ? "coratri_{$hoje}.csv"
                : "coratri_{$inicioFmt}_a_{$fimFmt}.csv";

            return response()->streamDownload(function () use ($report) {
                $out = fopen('php://output', 'w');

                // BOM UTF-8 para o Excel reconhecer acentuação
                fwrite($out, "\xEF\xBB\xBF");

                $sep = ';';
                $num = fn ($v) => number_format((float) $v, 2, ',', '');

                fputcsv($out, [
                    'Data', 'Usuário', 'Nome',
                    'Saldo Inicial (dia)',
                    'Depósitos (qtd)', 'Depósitos Bruto', 'Depósitos Líquido',
                    'Saques (qtd)', 'Saques Debitado', 'Saques Pago (PIX)',
                    'Taxas Depósito', 'Taxas Saque', 'Lucro Coratri',
                    'Saldo Final (dia)',
                ], $sep);

                foreach ($report['linhas'] as $linha) {
                    fputcsv($out, [
                        Carbon::parse($linha['data'])->format('d/m/Y'),
                        $linha['user_id'],
                        $linha['nome'],
                        $num($linha['saldo_inicial']),
                        $linha['depositos_qtd'],
                        $num($linha['depositos_bruto']),
                        $num($linha['depositos_liquido']),
                        $linha['saques_qtd'],
                        $num($linha['saques_debitado']),
                        $num($linha['saques_pago']),
                        $num($linha['taxa_depositos']),
                        $num($linha['taxa_saques']),
                        $num($linha['lucro']),
                        $num($linha['saldo_final']),
                    ], $sep);
                }

                $resumo = $report['resumo'];
                fputcsv($out, [], $sep);
                fputcsv($out, [
                    'TOTAIS', '', '',
                    '',
                    $resumo['depositos']['quantidade'],
                    $num($resumo['depositos']['valor_bruto']),
                    $num($resumo['depositos']['valor_liquido']),
                    $resumo['saques']['quantidade'],
                    $num($resumo['saques']['valor_debitado']),
                    $num($resumo['saques']['valor_pago']),
                    $num($resumo['lucro_depositos']),
                    $num($resumo['lucro_saques']),
                    $num($resumo['lucro_total']),
                    '',
                ], $sep);

                fclose($out);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Access-Control-Expose-Headers' => 'Content-Disposition',
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao exportar relatório de conciliação', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Erro ao exportar relatório de conciliação', 500);
        }
    }

    /**
     * Monta o relatório completo (resumo + linhas por usuário/dia).
     */
    private function buildReport(Carbon $dataInicio, Carbon $dataFim, ?string $filterUserId = null): array
    {
        // ---- Agregados diários de depósitos (cash-in confirmado) ----
        $depQuery = Solicitacoes::whereIn('status', Solicitacoes::CONFIRMED_REVENUE_STATUSES)
            ->whereBetween('date', [$dataInicio, $dataFim]);

        if ($filterUserId) {
            $depQuery->where('user_id', $filterUserId);
        }

        $dailyDeps = $depQuery
            ->selectRaw('user_id, DATE(date) as dia, COUNT(*) as qtd, SUM(amount) as bruto, SUM(COALESCE(deposito_liquido, amount)) as liquido, SUM(COALESCE(taxa_cash_in, 0)) as taxas')
            ->groupBy('user_id', DB::raw('DATE(date)'))
            ->get();

        // ---- Agregados diários de saques (cash-out confirmado) ----
        $saqQuery = SolicitacoesCashOut::whereIn('status', self::WITHDRAW_CONFIRMED_STATUSES)
            ->whereBetween('date', [$dataInicio, $dataFim]);

        if ($filterUserId) {
            $saqQuery->where('user_id', $filterUserId);
        }

        $dailySaques = $saqQuery
            ->selectRaw('user_id, DATE(date) as dia, COUNT(*) as qtd, SUM(COALESCE(valor_total_descontado, amount)) as debitado, SUM(COALESCE(cash_out_liquido, amount)) as pago, SUM(COALESCE(taxa_cash_out, 0)) as taxas')
            ->groupBy('user_id', DB::raw('DATE(date)'))
            ->get();

        // ---- Contas envolvidas ----
        $accounts = $dailyDeps->pluck('user_id')
            ->merge($dailySaques->pluck('user_id'))
            ->unique()
            ->values();

        // solicitacoes.user_id pode conter username OU users.user_id — mapear pelos dois
        $usersByKey = [];
        if ($accounts->isNotEmpty()) {
            $users = User::where(function ($q) use ($accounts) {
                $q->whereIn('user_id', $accounts)->orWhereIn('username', $accounts);
            })->get(['id', 'user_id', 'username', 'name', 'saldo', 'saldo_afiliado']);

            foreach ($users as $u) {
                if (!empty($u->user_id)) {
                    $usersByKey[$u->user_id] = $u;
                }
                if (!empty($u->username)) {
                    $usersByKey[$u->username] = $u;
                }
            }
        }

        // ---- Movimentos APÓS o fim do período (para ancorar o saldo atual no fim do período) ----
        $depsAfter = collect();
        $saquesAfter = collect();
        if ($accounts->isNotEmpty()) {
            $depsAfter = Solicitacoes::whereIn('status', Solicitacoes::CONFIRMED_REVENUE_STATUSES)
                ->where('date', '>', $dataFim)
                ->whereIn('user_id', $accounts)
                ->selectRaw('user_id, SUM(COALESCE(deposito_liquido, amount)) as total')
                ->groupBy('user_id')
                ->pluck('total', 'user_id');

            $saquesAfter = SolicitacoesCashOut::whereIn('status', self::WITHDRAW_CONFIRMED_STATUSES)
                ->where('date', '>', $dataFim)
                ->whereIn('user_id', $accounts)
                ->selectRaw('user_id, SUM(COALESCE(valor_total_descontado, amount)) as total')
                ->groupBy('user_id')
                ->pluck('total', 'user_id');
        }

        // ---- Indexar por conta => dia ----
        $depsByAccount = $dailyDeps->groupBy('user_id')->map(fn ($rows) => $rows->keyBy('dia'));
        $saquesByAccount = $dailySaques->groupBy('user_id')->map(fn ($rows) => $rows->keyBy('dia'));

        $linhas = [];

        foreach ($accounts as $account) {
            $user = $usersByKey[$account] ?? null;
            $saldoAtual = $user ? (float) ($user->saldo ?? 0) + (float) ($user->saldo_afiliado ?? 0) : 0.0;

            // Saldo estimado no fim do período: desfaz movimentos posteriores
            $running = $saldoAtual
                - (float) ($depsAfter[$account] ?? 0)
                + (float) ($saquesAfter[$account] ?? 0);

            $depDays = $depsByAccount->get($account, collect());
            $saqDays = $saquesByAccount->get($account, collect());

            $dias = $depDays->keys()->merge($saqDays->keys())->unique()->sortDesc()->values();

            foreach ($dias as $dia) {
                $dep = $depDays->get($dia);
                $saq = $saqDays->get($dia);

                $depLiquido = (float) ($dep->liquido ?? 0);
                $saqDebitado = (float) ($saq->debitado ?? 0);

                $saldoFinal = $running;
                $saldoInicial = $saldoFinal - $depLiquido + $saqDebitado;
                $running = $saldoInicial;

                $linhas[] = [
                    'data' => $dia,
                    'user_id' => $account,
                    'nome' => $user->name ?? $account,
                    'saldo_inicial' => round($saldoInicial, 2),
                    'depositos_qtd' => (int) ($dep->qtd ?? 0),
                    'depositos_bruto' => round((float) ($dep->bruto ?? 0), 2),
                    'depositos_liquido' => round($depLiquido, 2),
                    'saques_qtd' => (int) ($saq->qtd ?? 0),
                    'saques_debitado' => round($saqDebitado, 2),
                    'saques_pago' => round((float) ($saq->pago ?? 0), 2),
                    'taxa_depositos' => round((float) ($dep->taxas ?? 0), 2),
                    'taxa_saques' => round((float) ($saq->taxas ?? 0), 2),
                    'lucro' => round((float) ($dep->taxas ?? 0) + (float) ($saq->taxas ?? 0), 2),
                    'saldo_final' => round($saldoFinal, 2),
                ];
            }
        }

        // Ordenar: data desc, depois maior movimento primeiro
        usort($linhas, function ($a, $b) {
            return [$b['data'], $b['depositos_liquido'] + $b['saques_debitado']]
                <=> [$a['data'], $a['depositos_liquido'] + $a['saques_debitado']];
        });

        // ---- Resumo do período ----
        $resumo = [
            'lucro_depositos' => round((float) $dailyDeps->sum('taxas'), 2),
            'lucro_saques' => round((float) $dailySaques->sum('taxas'), 2),
            'lucro_total' => round((float) $dailyDeps->sum('taxas') + (float) $dailySaques->sum('taxas'), 2),
            'depositos' => [
                'quantidade' => (int) $dailyDeps->sum('qtd'),
                'valor_bruto' => round((float) $dailyDeps->sum('bruto'), 2),
                'valor_liquido' => round((float) $dailyDeps->sum('liquido'), 2),
            ],
            'saques' => [
                'quantidade' => (int) $dailySaques->sum('qtd'),
                'valor_debitado' => round((float) $dailySaques->sum('debitado'), 2),
                'valor_pago' => round((float) $dailySaques->sum('pago'), 2),
            ],
            'usuarios_ativos' => $accounts->count(),
        ];

        return [
            'periodo' => [
                'inicio' => $dataInicio->format('Y-m-d H:i:s'),
                'fim' => $dataFim->format('Y-m-d H:i:s'),
            ],
            'resumo' => $resumo,
            'linhas' => $linhas,
            'observacao' => 'Saldos inicial/final são estimados a partir do saldo atual da carteira e dos movimentos confirmados. Ajustes manuais de saldo podem gerar divergências.',
        ];
    }

    /**
     * Resolve o período (mesma semântica do dashboard admin).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolvePeriod(string $periodo): array
    {
        switch ($periodo) {
            case 'hoje':
                return [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()];
            case 'ontem':
                return [Carbon::yesterday()->startOfDay(), Carbon::yesterday()->endOfDay()];
            case '7dias':
                return [Carbon::today()->subDays(6)->startOfDay(), Carbon::today()->endOfDay()];
            case '30dias':
            case 'tudo':
                // Relatório limitado a 30 dias (mesmo com filtro "tudo" do dashboard)
                return [Carbon::today()->subDays(29)->startOfDay(), Carbon::today()->endOfDay()];
            default:
                if (str_contains($periodo, ':')) {
                    [$start, $end] = explode(':', $periodo);

                    return [Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay()];
                }

                return [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()];
        }
    }
}
