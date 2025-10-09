<?php

namespace App\Http\Controllers\Admin\Financeiro;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Solicitacoes;
use Carbon\Carbon;

class EntradasController extends Controller
{
    public function index(Request $request)
    {
        $dataHoje = Carbon::today()->toDateString();
        $mesAtual = Carbon::now()->format('Y-m');

        $totalaprovadasHoje = $this->contarTransacoes('PAID_OUT', $dataHoje);
        $totalaprovadasMes = $this->contarTransacoes('PAID_OUT', null, $mesAtual);
        $totalaprovadas = $this->contarTransacoes('PAID_OUT');
        $totalaprovadas = $this->contarTransacoes();

        $valorAprovadoHoje = $this->somarValores('amount', 'PAID_OUT', $dataHoje);
        $valorAprovadoMes = $this->somarValores('amount', 'PAID_OUT', null, $mesAtual);
        $valorAprovadoTotal = $this->somarValores('amount', 'PAID_OUT');

        $valorSaqueAprovadoHoje = $this->somarValores('deposito_liquido', 'PAID_OUT', $dataHoje);
        $valorSaqueAprovadoMes = $this->somarValores('deposito_liquido', 'PAID_OUT', null, $mesAtual);
        $valorSaqueAprovadoTotal = $this->somarValores('deposito_liquido', 'PAID_OUT');

        $totalsolicitacoes = Solicitacoes::count();

        $limit = PHP_INT_MAX; // Número de registros por página
        $page = $request->input('page', 1); // Página atual
        $offset = ($page - 1) * $limit;

        // Parâmetros de filtro
        $statusFilter = $request->input('status', '');
        $methodFilter = $request->input('method', '');
        $periodFilter = $request->input('period', '');
        $searchFilter = $request->input('search', '');
        $dataInicio = $request->input('data_inicio', '');
        $dataFim = $request->input('data_fim', '');

        // Query para obter a soma filtrada com status PAID_OUT
        $query = Solicitacoes::where('status', '!=', '');

        // Aplicar filtros
        if ($statusFilter) {
            $query->where('status', $statusFilter);
        }

        if ($methodFilter) {
            if ($methodFilter === 'med_chargeback') {
                // Para MED/CHARGEBACK, filtrar por status MEDIATION
                $query->where('status', 'MEDIATION');
            } else {
                $query->where('method', $methodFilter);
            }
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
                $q->where('idTransaction', 'like', '%' . $searchFilter . '%')
                  ->orWhere('user_id', 'like', '%' . $searchFilter . '%')
                  ->orWhere('amount', 'like', '%' . $searchFilter . '%');
            });
        }

        if (!empty($dataInicio) && !empty($dataFim)) {
            $query->whereBetween('date', [$dataInicio, $dataFim]);
        }

        $totalResults = $query->selectRaw('SUM(deposito_liquido) AS total_deposito_liquido_filtrado, SUM(amount) AS total_deposito_bruto_filtrada')->first();

        $total_deposito_liquido_filtrado = $totalResults->total_deposito_liquido_filtrado ?: 0;
        $total_deposito_bruto_filtrada = $totalResults->total_deposito_bruto_filtrada ?: 0;

        $lucro_plataforma_filtrada = $total_deposito_bruto_filtrada - $total_deposito_liquido_filtrado;

        // Consulta para obter o número total de registros
        $totalRecords = Solicitacoes::whereIn('status', ['RELEASE','PAID_OUT', 'CANCELED', 'WAITING_FOR_APPROVAL']);

        if (!empty($dataInicio) && !empty($dataFim)) {
            $totalRecords->whereBetween('date', [$dataInicio, $dataFim]);
        }

        $totalRecords = $totalRecords->count();
        $totalPages = ceil($totalRecords / $limit);

        // Consulta para obter os registros com paginação e filtros aplicados
        $cashOutsQuery = Solicitacoes::whereIn('status', ['RELEASE','PAID_OUT', 'CANCELED', 'WAITING_FOR_APPROVAL']);

        // Aplicar os mesmos filtros na query principal
        if ($statusFilter) {
            $cashOutsQuery->where('status', $statusFilter);
        }

        if ($methodFilter) {
            if ($methodFilter === 'med_chargeback') {
                // Para MED/CHARGEBACK, filtrar por status MEDIATION
                $cashOutsQuery->where('status', 'MEDIATION');
            } else {
                $cashOutsQuery->where('method', $methodFilter);
            }
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
                $q->where('idTransaction', 'like', '%' . $searchFilter . '%')
                  ->orWhere('user_id', 'like', '%' . $searchFilter . '%')
                  ->orWhere('amount', 'like', '%' . $searchFilter . '%');
            });
        }

        if (!empty($dataInicio) && !empty($dataFim)) {
            $cashOutsQuery->whereBetween('date', [$dataInicio, $dataFim]);
        }

        $cashOuts = $cashOutsQuery->orderBy('date', 'desc')->paginate($limit);

        // Retornar a view com os dados
        return view('admin.financeiro.entradas', compact(
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
            'total_deposito_liquido_filtrado',
            'total_deposito_bruto_filtrada',
            'lucro_plataforma_filtrada',
            'totalPages',
            'page',
            'limit',
            'dataInicio',
            'dataFim',
            'statusFilter',
            'methodFilter',
            'periodFilter',
            'searchFilter'
        ));
    }

    private function contarTransacoes($status = null, $data = null, $mes = null)
    {
        return DB::table('solicitacoes')
            ->when($status, fn($query) => $query->where('status', $status))
            ->when($data, fn($query) => $query->whereDate('date', $data))
            ->when($mes, fn($query) => $query->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$mes]))
            ->count();
    }

    private function somarValores($campo, $status = null, $data = null, $mes = null)
    {
        return DB::table('solicitacoes')
            ->when($status, fn($query) => $query->where('status', $status))
            ->when($data, fn($query) => $query->whereDate('date', $data))
            ->when($mes, fn($query) => $query->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$mes]))
            ->sum($campo) ?? 0;
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:PAID_OUT,WAITING_FOR_APPROVAL,RELEASE,CANCELLED'
        ]);

        $solicitacao = Solicitacoes::findOrFail($id);
        $solicitacao->status = $request->status;
        $solicitacao->save();

        return response()->json([
            'success' => true,
            'message' => 'Status atualizado com sucesso!'
        ]);
    }

    public function getTransactionDetails($id)
    {
        try {
            $solicitacao = Solicitacoes::with('user')->findOrFail($id);
            
            // Buscar dados do usuário
            $user = $solicitacao->user;
            $clientName = $user ? $user->name : 'N/A';
            
            // Preparar dados da transação no formato do modal
            $transaction = [
                'id' => $solicitacao->id,
                'idTransaction' => $solicitacao->idTransaction,
                'amount' => $solicitacao->amount,
                'deposito_liquido' => $solicitacao->deposito_liquido,
                'method' => $solicitacao->method,
                'status' => $solicitacao->status,
                'date' => $solicitacao->date,
                'client_name' => $clientName,
                'user_id' => $solicitacao->user_id,
                'taxa' => $solicitacao->amount - $solicitacao->deposito_liquido,
                'card_number' => $solicitacao->card_number ?? '**** **** **** ****',
                'card_expiry' => $solicitacao->card_expiry ?? '12/25',
                'card_brand' => $solicitacao->card_brand ?? 'VISA',
                'pix_key' => $solicitacao->pix_key ?? null,
                'billet_url' => $solicitacao->billet_url ?? null,
                'empresa' => 'HKPAY',
                'assinatura_status' => 'ATIVA',
                'assinatura_data' => $solicitacao->date
            ];

            return response()->json([
                'success' => true,
                'transaction' => $transaction
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar detalhes da transação: ' . $e->getMessage()
            ], 500);
        }
    }

    public function enviarMediacao($id)
    {
        try {
            // Validar ID
            if (!is_numeric($id) || $id <= 0) {
                return response()->json(['success' => false, 'message' => 'ID inválido'], 400);
            }
            
            \Log::info('Tentativa de envio para mediação', ['id' => $id]);
            
            $solicitacao = Solicitacoes::findOrFail((int)$id);
            \Log::info('Solicitação encontrada', ['status' => $solicitacao->status]);
            
            // Verificar se a transação pode ser enviada para mediação
            if ($solicitacao->status !== 'PAID_OUT') {
                \Log::warning('Tentativa de mediação em transação não aprovada - Status: ' . $solicitacao->status);
                return response()->json(['success' => false, 'message' => 'Apenas transações aprovadas podem ser enviadas para mediação'], 400);
            }
            
            // Atualizar status para mediação
            $solicitacao->update([
                'status' => 'MEDIATION',
                'updated_at' => now()
            ]);
            
            // Buscar usuário para bloquear o valor
            $user = $solicitacao->user;
            if ($user) {
                $saldoAnterior = $user->saldo;
                $valorBloquear = $solicitacao->deposito_liquido;
                
                \Log::info('Mediação - Antes do decrement', [
                    'user_id' => $user->user_id,
                    'saldo_anterior' => $saldoAnterior,
                    'valor_bloquear' => $valorBloquear,
                    'transaction_id' => $solicitacao->idTransaction
                ]);
                
                // Bloquear o valor (remover do saldo disponível)
                $user->decrement('saldo', $valorBloquear);
                
                // Recarregar o usuário para ver o saldo atualizado
                $user->refresh();
                
                \Log::info('Mediação - Após decrement', [
                    'user_id' => $user->user_id,
                    'saldo_anterior' => $saldoAnterior,
                    'saldo_atual' => $user->saldo,
                    'valor_bloqueado' => $valorBloquear,
                    'transaction_id' => $solicitacao->idTransaction
                ]);
                
                // Adicionar ao saldo bloqueado (se existir campo) ou criar log
                // Aqui você pode implementar a lógica específica para bloquear o valor
                // Por exemplo, criar um registro em uma tabela de valores bloqueados
            } else {
                \Log::warning('Mediação - Usuário não encontrado', [
                    'solicitacao_id' => $id,
                    'user_id_from_solicitacao' => $solicitacao->user_id
                ]);
            }
            
            \Log::info('Mediação executada com sucesso', ['id' => $id]);
            
            return response()->json([
                'success' => true, 
                'message' => 'Transação enviada para mediação com sucesso. O valor foi bloqueado e ficará pendente para liberação manual.'
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Erro ao enviar transação para mediação: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erro ao enviar transação para mediação: ' . $e->getMessage()], 500);
        }
    }

    public function reverterMediacao($id)
    {
        try {
            // Validar ID
            if (!is_numeric($id) || $id <= 0) {
                return response()->json(['success' => false, 'message' => 'ID inválido'], 400);
            }
            
            \Log::info('Tentativa de reversão de mediação', ['id' => $id]);
            
            $solicitacao = Solicitacoes::findOrFail((int)$id);
            \Log::info('Solicitação encontrada', ['status' => $solicitacao->status]);
            
            // Verificar se a transação está em mediação
            if ($solicitacao->status !== 'MEDIATION') {
                \Log::warning('Tentativa de reversão em transação não em mediação - Status: ' . $solicitacao->status);
                return response()->json(['success' => false, 'message' => 'Apenas transações em mediação podem ser revertidas'], 400);
            }
            
            // Buscar usuário para liberar o valor
            $user = $solicitacao->user;
            if ($user) {
                // Liberar o valor (adicionar de volta ao saldo disponível)
                $user->increment('saldo', $solicitacao->deposito_liquido);
            }
            
            // Atualizar status para aprovado novamente
            $solicitacao->update([
                'status' => 'PAID_OUT',
                'updated_at' => now()
            ]);
            
            \Log::info('Reversão de mediação executada com sucesso', ['id' => $id]);
            
            return response()->json([
                'success' => true, 
                'message' => 'Mediação revertida com sucesso. O valor foi liberado e voltou ao saldo do usuário.'
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Erro ao reverter mediação: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erro ao reverter mediação: ' . $e->getMessage()], 500);
        }
    }

    public function exportCsv(Request $request)
    {
        // Aplicar os mesmos filtros do index
        $statusFilter = $request->input('status', '');
        $methodFilter = $request->input('method', '');
        $periodFilter = $request->input('period', '');
        $searchFilter = $request->input('search', '');
        $dataInicio = $request->input('data_inicio', '');
        $dataFim = $request->input('data_fim', '');

        $query = Solicitacoes::where('status', '!=', '');

        // Aplicar filtros
        if ($statusFilter) {
            $query->where('status', $statusFilter);
        }

        if ($methodFilter) {
            if ($methodFilter === 'med_chargeback') {
                $query->where('status', 'MEDIATION');
            } else {
                $query->where('method', $methodFilter);
            }
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
                $q->where('idTransaction', 'like', '%' . $searchFilter . '%')
                  ->orWhere('user_id', 'like', '%' . $searchFilter . '%')
                  ->orWhere('amount', 'like', '%' . $searchFilter . '%');
            });
        }

        if (!empty($dataInicio) && !empty($dataFim)) {
            $query->whereBetween('date', [$dataInicio, $dataFim]);
        }

        $data = $query->orderByDesc('date')->get();

        $filename = 'entradas_' . date('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($data) {
            $file = fopen('php://output', 'w');
            
            // Cabeçalho CSV
            fputcsv($file, [
                'Meio',
                'User ID',
                'Transação ID',
                'Valor',
                'Valor Líquido',
                'Status',
                'Data',
                'Taxa'
            ]);

            // Dados
            foreach ($data as $row) {
                fputcsv($file, [
                    $row->method ?? '',
                    $row->user_id ?? '',
                    $row->idTransaction ?? '',
                    $row->amount ?? '',
                    $row->deposito_liquido ?? '',
                    $row->status ?? '',
                    $row->date ?? '',
                    number_format((float)$row->amount - (float)$row->deposito_liquido, 2, ',', '.')
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
