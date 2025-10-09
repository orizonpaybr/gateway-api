<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Helpers\Helper;
use App\Models\CheckoutBuild;

class DashboardControlller extends Controller
{
    public function index(Request $request)
    {
        Helper::calculaSaldoLiquido(auth()->user()->user_id);

        $userId = Auth::user()->user_id;
        $nome = Auth::user()->name;
        $status = Auth::user()->status;
        $permission = Auth::user()->permission;
        
        // Validar e sanitizar entradas
        $produtoFiltro = $request->input('produto', 'todos');
        $periodoFiltro = $request->input('periodo', 'tudo');
        
        // Validação de entrada
        $allowedProdutos = ['todos', 'produto'];
        $allowedPeriodos = ['tudo', 'hoje', 'ontem', '7dias', '30dias'];
        
        if (!in_array($produtoFiltro, $allowedProdutos)) {
            $produtoFiltro = 'todos';
        }
        
        if (!in_array($periodoFiltro, $allowedPeriodos) && !str_contains($periodoFiltro, ':')) {
            $periodoFiltro = 'tudo';
        }

        [$startDate, $endDate] = $this->resolvePeriodo($periodoFiltro);

        // Base queries
        $solicitacoes = DB::table('solicitacoes')->where('user_id', $userId);
        $solicitacoesPaid = DB::table('solicitacoes')->where('user_id', $userId)->where('status', 'PAID_OUT');
        $solicitacoesCashOut = DB::table('solicitacoes_cash_out')->where('user_id', $userId);
        $sumDepositoLiquidoQuery = DB::table('solicitacoes')->where('user_id', $userId)->where('status', 'PAID_OUT');
        $sumSaquesAprovadosQuery = DB::table('solicitacoes_cash_out')->where('user_id', $userId)->where('status', 'COMPLETED');

        // Aplica filtros de data e produto
        $this->aplicarFiltros($solicitacoes, $startDate, $endDate, $produtoFiltro);
        $this->aplicarFiltros($solicitacoesPaid, $startDate, $endDate, $produtoFiltro);
        $this->aplicarFiltros($solicitacoesCashOut, $startDate, $endDate, $produtoFiltro);
        $this->aplicarFiltros($sumDepositoLiquidoQuery, $startDate, $endDate, $produtoFiltro);
        $this->aplicarFiltros($sumSaquesAprovadosQuery, $startDate, $endDate, $produtoFiltro);

        // Executa e coleta resultados
        $ultimasSolicitacoes = (clone $solicitacoes)->where('status', 'PAID_OUT')->orderByDesc('id')->limit(4)->get();
        $ultimosSaques = (clone $solicitacoesCashOut)->where('status', 'COMPLETED')->orderByDesc('id')->limit(4)->get();

        $ultimasTransacoes = $ultimasSolicitacoes->merge($ultimosSaques)
            ->whereIn('status', ['PAID_OUT', 'COMPLETED'])
            ->sortByDesc(fn($item) => Carbon::parse($item->date))
            ->take(4);
      
      	if(!is_null($startDate) && !is_null($endDate)){
 			$ultimasTransacoes = $ultimasSolicitacoes->merge($ultimosSaques)
            ->whereBetween('date', [$startDate, $endDate])
            ->sortByDesc(fn($item) => Carbon::parse($item->date))
            ->take(4);
      
        }

        // Totais
        $totalPaidOut = $solicitacoesPaid->count();
        $totalRequests = $solicitacoes->count();
        $sumAmountPaidOut = $solicitacoesPaid->sum('amount');
        $sumDepositoLiquido = $sumDepositoLiquidoQuery->sum('deposito_liquido');
        $sumSaquesAprovados = $sumSaquesAprovadosQuery->sum('cash_out_liquido');

        $saldoliquido = (float) $sumDepositoLiquido - (float) $sumSaquesAprovados;

        // Dados para gráfico usando Query Builder seguro
        $result_solicitacoes = DB::table('solicitacoes')
            ->select(DB::raw('DATE(date) as dia'), DB::raw('SUM(amount) as valor'))
            ->where('user_id', $userId)
            ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
                $query->whereBetween('date', [$startDate, $endDate]);
            })
            ->groupBy('dia')
            ->orderBy('dia')
            ->get();

        $dates = $result_solicitacoes->pluck('dia')->toArray();
        $values = $result_solicitacoes->pluck('valor')->toArray();

        $realDate = now()->format('d/m/Y');

        // Contar clientes ativos reais do sistema
        $clientesAtivos = DB::table('users')
            ->where('banido', 0) // Não banidos
            ->where('status', 1) // Aprovados
            ->where('permission', 1) // Usuários comuns (não admin/gerente)
            ->count();

        // Calcular crescimento de vendas (comparar período atual vs anterior)
        $vendasAtual = DB::table('solicitacoes')
            ->where('user_id', $userId)
            ->where('status', 'PAID_OUT')
            ->whereBetween('date', [$startDate, $endDate])
            ->sum('amount');

        // Período anterior (mesmo intervalo de dias)
        $diasPeriodo = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate));
        $dataInicioAnterior = Carbon::parse($startDate)->subDays($diasPeriodo + 1);
        $dataFimAnterior = Carbon::parse($startDate)->subDay();

        $vendasAnterior = DB::table('solicitacoes')
            ->where('user_id', $userId)
            ->where('status', 'PAID_OUT')
            ->whereBetween('date', [$dataInicioAnterior, $dataFimAnterior])
            ->sum('amount');

        // Calcular porcentagem de crescimento
        $crescimentoVendas = 0;
        if ($vendasAnterior > 0) {
            $crescimentoVendas = (($vendasAtual - $vendasAnterior) / $vendasAnterior) * 100;
        } elseif ($vendasAtual > 0) {
            $crescimentoVendas = 100; // Se não havia vendas antes e agora há
        }

        // Produtos disponíveis (opcional: pode vir de config, tabela, etc.)
        $produtos = CheckoutBuild::where('user_id', auth()->user()->user_id); // ajuste conforme seu sistema

		$solicitacoes = (clone $solicitacoes);
        auth()->user()->fresh();
        return view('dashboard', compact(
            'nome',
            'status',
            'result_solicitacoes',
            'permission',
            'solicitacoes',
            'solicitacoesPaid',
            'totalPaidOut',
            'totalRequests',
            'sumAmountPaidOut',
            'sumDepositoLiquido',
            'sumSaquesAprovados',
            'ultimasTransacoes',
            'saldoliquido',
            'realDate',
            'dates',
            'values',
            'produtos',
            'clientesAtivos',
            'crescimentoVendas'
        ));
    }

    private function aplicarFiltros($query, $startDate, $endDate, $produtoFiltro)
    {
        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }

        if ($produtoFiltro !== 'todos') {
            $query->where('descricao_transacao', 'PRODUTO');
        }

        return $query;
    }

    private function resolvePeriodo($periodo)
    {
        $hoje = Carbon::today();

        return match ($periodo) {
            'hoje'     => [$hoje, $hoje->copy()->endOfDay()],
            'ontem'    => [Carbon::yesterday(), Carbon::yesterday()->copy()->endOfDay()],
            '7dias'    => [Carbon::now()->subDays(6)->startOfDay(), Carbon::now()->endOfDay()],
            '30dias'   => [Carbon::now()->subDays(30)->startOfDay(), Carbon::now()->endOfDay()],
            default    => str_contains($periodo, ':')
                ? $this->validarDatasPersonalizadas($periodo)
                : [null, null],
        };
    }
    
    private function validarDatasPersonalizadas($periodo)
    {
        $datas = explode(':', $periodo);
        
        if (count($datas) !== 2) {
            return [null, null];
        }
        
        $dataInicio = $datas[0];
        $dataFim = $datas[1];
        
        // Validar formato de data (YYYY-MM-DD)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicio) || 
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) {
            return [null, null];
        }
        
        // Validar se as datas são válidas
        try {
            $inicio = Carbon::createFromFormat('Y-m-d', $dataInicio);
            $fim = Carbon::createFromFormat('Y-m-d', $dataFim);
            
            // Validar se data início não é maior que data fim
            if ($inicio->gt($fim)) {
                return [null, null];
            }
            
            // Validar se não é muito antigo (máximo 1 ano)
            if ($inicio->lt(Carbon::now()->subYear())) {
                return [null, null];
            }
            
            return [$inicio->startOfDay(), $fim->endOfDay()];
        } catch (\Exception $e) {
            return [null, null];
        }
    }
}
