<?php

namespace App\Http\Controllers\Admin\Financeiro;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\SolicitacoesCashOut;
use Carbon\Carbon;

class SaidasController extends Controller
{
    public function index(Request $request)
    {

        $dataHoje = Carbon::today()->toDateString();
        $mesAtual = Carbon::now()->format('Y-m');

        $totalaprovadasHoje = $this->contarTransacoes(['COMPLETED', 'PAID_OUT'], $dataHoje);
        $totalaprovadasMes = $this->contarTransacoes(['COMPLETED', 'PAID_OUT'], null, $mesAtual);
        $totalaprovadas = $this->contarTransacoes(['COMPLETED', 'PAID_OUT']);
        $totalaprovadas = $this->contarTransacoes();

        $valorAprovadoHoje = $this->somarValores('amount', ['COMPLETED', 'PAID_OUT'], $dataHoje);
        $valorAprovadoMes = $this->somarValores('amount', ['COMPLETED', 'PAID_OUT'], null, $mesAtual);
        $valorAprovadoTotal = $this->somarValores('amount', ['COMPLETED', 'PAID_OUT']);

        $valorSaqueAprovadoHoje = $this->somarValores('cash_out_liquido', ['COMPLETED', 'PAID_OUT'], $dataHoje);
        $valorSaqueAprovadoMes = $this->somarValores('cash_out_liquido', ['COMPLETED', 'PAID_OUT'], null, $mesAtual);
        $valorSaqueAprovadoTotal = $this->somarValores('cash_out_liquido', ['COMPLETED', 'PAID_OUT']);

        $totalsolicitacoes = SolicitacoesCashOut::count();

        $limit = 10000; // Número de registros por página
        $page = $request->input('page', 1); // Página atual
        $offset = ($page - 1) * $limit;

        // Parâmetros de filtro
        $statusFilter = $request->input('status', '');
        $periodFilter = $request->input('period', '');
        $searchFilter = $request->input('search', '');
        $dataInicio = $request->input('data_inicio', '');
        $dataFim = $request->input('data_fim', '');

        // Query para obter a soma filtrada com status COMPLETED
        $query = SolicitacoesCashOut::where('id', '!=', 0);

        // Aplicar filtros
        if ($statusFilter) {
            $query->where('status', $statusFilter);
        }

        if ($periodFilter) {
            $now = Carbon::now();
            switch ($periodFilter) {
                case 'today':
                    $query->whereDate('date', $now->toDateString());
                    break;
                case 'week':
                    $query->where('date', '>=', $now->copy()->startOfWeek());
                    break;
                case 'month':
                    $query->where('date', '>=', $now->copy()->startOfMonth());
                    break;
                case 'year':
                    $query->whereYear('date', $now->year);
                    break;
            }
        }

        if ($searchFilter) {
            $query->where(function($q) use ($searchFilter) {
                $q->where('externalreference', 'like', '%' . $searchFilter . '%')
                  ->orWhere('user_id', 'like', '%' . $searchFilter . '%')
                  ->orWhere('amount', 'like', '%' . $searchFilter . '%')
                  ->orWhere('beneficiaryname', 'like', '%' . $searchFilter . '%')
                  ->orWhere('pixkey', 'like', '%' . $searchFilter . '%');
            });
        }

        if (!empty($dataInicio) && !empty($dataFim)) {
            $query->whereBetween('date', [$dataInicio, $dataFim]);
        }

        $totalResults = $query->selectRaw('SUM(cash_out_liquido) AS total_cash_out_liquido_filtrado, SUM(amount) AS total_cash_out_bruto_filtrada')->first();

        $total_cash_out_liquido_filtrado = $totalResults->total_cash_out_liquido_filtrado ?: 0;
        $total_cash_out_bruto_filtrada = $totalResults->total_cash_out_bruto_filtrada ?: 0;

        $lucro_plataforma_filtrada = $total_cash_out_liquido_filtrado - $total_cash_out_bruto_filtrada;

        // Consulta para obter o número total de registros
        $totalRecords = SolicitacoesCashOut::whereIn('status', ['COMPLETED', 'CANCELLED', 'PENDING', 'PAID_OUT']);

        if (!empty($dataInicio) && !empty($dataFim)) {
            $totalRecords->whereBetween('date', [$dataInicio, $dataFim]);
        }

        $totalRecords = $totalRecords->count();
        $totalPages = ceil($totalRecords / $limit);

        // Consulta para obter os registros com paginação e filtros aplicados
        $cashOutsQuery = SolicitacoesCashOut::whereIn('status', ['COMPLETED', 'CANCELLED', 'PENDING', 'PAID_OUT']);

        // Aplicar os mesmos filtros na query principal
        if ($statusFilter) {
            $cashOutsQuery->where('status', $statusFilter);
        }

        if ($periodFilter) {
            $now = Carbon::now();
            switch ($periodFilter) {
                case 'today':
                    $cashOutsQuery->whereDate('date', $now->toDateString());
                    break;
                case 'week':
                    $cashOutsQuery->where('date', '>=', $now->copy()->startOfWeek());
                    break;
                case 'month':
                    $cashOutsQuery->where('date', '>=', $now->copy()->startOfMonth());
                    break;
                case 'year':
                    $cashOutsQuery->whereYear('date', $now->year);
                    break;
            }
        }

        if ($searchFilter) {
            $cashOutsQuery->where(function($q) use ($searchFilter) {
                $q->where('externalreference', 'like', '%' . $searchFilter . '%')
                  ->orWhere('user_id', 'like', '%' . $searchFilter . '%')
                  ->orWhere('amount', 'like', '%' . $searchFilter . '%')
                  ->orWhere('beneficiaryname', 'like', '%' . $searchFilter . '%')
                  ->orWhere('pixkey', 'like', '%' . $searchFilter . '%');
            });
        }

        if (!empty($dataInicio) && !empty($dataFim)) {
            $cashOutsQuery->whereBetween('date', [$dataInicio, $dataFim]);
        }

        $cashOuts = $cashOutsQuery->orderBy('date', 'desc')->paginate($limit);


        return view('admin.financeiro.saidas', compact(
            'totalaprovadasHoje',
            'totalaprovadasMes',
            'totalaprovadas',
            'totalaprovadas',
            'valorAprovadoHoje',
            'valorAprovadoMes',
            'valorAprovadoTotal',
            'valorSaqueAprovadoHoje',
            'valorSaqueAprovadoMes',
            'valorSaqueAprovadoTotal',
            'totalsolicitacoes',
            'cashOuts',
            'total_cash_out_liquido_filtrado',
            'total_cash_out_bruto_filtrada',
            'lucro_plataforma_filtrada',
            'totalPages',
            'page',
            'limit',
            'dataInicio',
            'dataFim',
            'statusFilter',
            'periodFilter',
            'searchFilter'
        ));
    }

    private function contarTransacoes($status = null, $data = null, $mes = null)
    {
        return DB::table('solicitacoes_cash_out')
            ->when($status, function($query) use ($status) {
                if (is_array($status)) {
                    return $query->whereIn('status', $status);
                }
                return $query->where('status', $status);
            })
            ->when($data, fn($query) => $query->whereDate('date', $data))
            ->when($mes, fn($query) => $query->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$mes]))
            ->count();
    }

    private function somarValores($campo, $status = null, $data = null, $mes = null)
    {
        return DB::table('solicitacoes_cash_out')
            ->when($status, function($query) use ($status) {
                if (is_array($status)) {
                    return $query->whereIn('status', $status);
                }
                return $query->where('status', $status);
            })
            ->when($data, fn($query) => $query->whereDate('date', $data))
            ->when($mes, fn($query) => $query->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$mes]))
            ->sum($campo) ?? 0;
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:COMPLETED,PAID_OUT,PENDING,REJECTED,CANCELLED'
        ]);

        $solicitacao = SolicitacoesCashOut::findOrFail($id);
        $statusAnterior = $solicitacao->status;
        $solicitacao->status = $request->status;
        $solicitacao->save();

        // Buscar o usuário para log
        $user = \App\Models\User::where('user_id', $solicitacao->user_id)->first();
        if ($user) {
            // Log da mudança de status do saque
            \App\Helpers\BalanceLogHelper::logSaqueOperation(
                'STATUS_CHANGE',
                $user,
                $solicitacao->amount,
                [
                    'solicitacao_id' => $solicitacao->id,
                    'status_anterior' => $statusAnterior,
                    'status_novo' => $request->status,
                    'valor_saque' => $solicitacao->amount,
                    'valor_liquido' => $solicitacao->cash_out_liquido,
                    'operacao' => 'updateStatus'
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Status atualizado com sucesso!'
        ]);
    }

    public function exportCsv(Request $request)
    {
        // Aplicar os mesmos filtros do index
        $statusFilter = $request->input('status', '');
        $periodFilter = $request->input('period', '');
        $searchFilter = $request->input('search', '');
        $dataInicio = $request->input('data_inicio', '');
        $dataFim = $request->input('data_fim', '');

        $query = SolicitacoesCashOut::where('id', '!=', 0);

        // Aplicar filtros
        if ($statusFilter) {
            $query->where('status', $statusFilter);
        }

        if ($periodFilter) {
            $now = Carbon::now();
            switch ($periodFilter) {
                case 'today':
                    $query->whereDate('date', $now->toDateString());
                    break;
                case 'week':
                    $query->where('date', '>=', $now->copy()->startOfWeek());
                    break;
                case 'month':
                    $query->where('date', '>=', $now->copy()->startOfMonth());
                    break;
                case 'year':
                    $query->whereYear('date', $now->year);
                    break;
            }
        }

        if ($searchFilter) {
            $query->where(function($q) use ($searchFilter) {
                $q->where('externalreference', 'like', '%' . $searchFilter . '%')
                  ->orWhere('user_id', 'like', '%' . $searchFilter . '%')
                  ->orWhere('beneficiaryname', 'like', '%' . $searchFilter . '%')
                  ->orWhere('pixkey', 'like', '%' . $searchFilter . '%');
            });
        }

        if (!empty($dataInicio) && !empty($dataFim)) {
            $query->whereBetween('date', [$dataInicio, $dataFim]);
        }

        $data = $query->orderByDesc('date')->get();

        $filename = 'saidas_' . date('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($data) {
            $file = fopen('php://output', 'w');
            
            // Cabeçalho CSV
            fputcsv($file, [
                'User ID',
                'Transação ID',
                'Valor',
                'Valor Líquido',
                'Status',
                'Nome',
                'Chave PIX',
                'Documento',
                'Data',
                'Taxa',
                'Resposta da adquirência'
            ]);

            // Dados
            foreach ($data as $row) {
                fputcsv($file, [
                    $row->user_id ?? '',
                    $row->externalreference ?? '',
                    $row->amount ?? '',
                    $row->cash_out_liquido ?? '',
                    $row->status ?? '',
                    $row->beneficiaryname ?? '',
                    $row->pixkey ?? '',
                    $row->beneficiarydocument ?? '',
                    $row->date ?? '',
                    number_format((float)$row->amount - (float)$row->cash_out_liquido, 2, ',', '.'),
                    $row->descricao_externa ?? ''
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
