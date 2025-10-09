<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SplitPayment;
use App\Models\User;
use App\Traits\SplitTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SplitController extends Controller
{
    /**
     * Lista todos os splits
     */
    public function index(Request $request)
    {
        $query = SplitPayment::with(['solicitacao', 'user']);

        // Filtros
        if ($request->filled('status')) {
            $query->where('split_status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('split_type', $request->type);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [
                Carbon::parse($request->start_date)->startOfDay(),
                Carbon::parse($request->end_date)->endOfDay()
            ]);
        }

        $splits = $query->orderBy('created_at', 'desc')->paginate(20);
        
        $users = User::select('user_id', 'name', 'email')->get();
        
        $stats = SplitTrait::getSplitStats();
        
        return view('admin.splits.index', compact('splits', 'users', 'stats'));
    }

    /**
     * Mostra detalhes de um split
     */
    public function show(SplitPayment $split)
    {
        $split->load(['solicitacao', 'user']);
        return view('admin.splits.show', compact('split'));
    }

    /**
     * Processa splits pendentes
     */
    public function processPending()
    {
        try {
            $results = SplitTrait::processPendingSplits();
            
            $successCount = collect($results)->where('result.status', 'completed')->count();
            $failedCount = collect($results)->where('result.status', 'failed')->count();
            
            return back()->with('success', 
                "Processamento concluído! {$successCount} splits processados com sucesso, {$failedCount} falharam."
            );
            
        } catch (\Exception $e) {
            Log::error('[ADMIN][SPLIT] Erro ao processar splits pendentes', [
                'error' => $e->getMessage()
            ]);
            
            return back()->with('error', 'Erro ao processar splits: ' . $e->getMessage());
        }
    }

    /**
     * Cancela um split
     */
    public function cancel(SplitPayment $split, Request $request)
    {
        try {
            $reason = $request->input('reason', 'Cancelado pelo administrador');
            
            if (SplitTrait::cancelSplit($split, $reason)) {
                return back()->with('success', 'Split cancelado com sucesso!');
            } else {
                return back()->with('error', 'Não foi possível cancelar este split.');
            }
            
        } catch (\Exception $e) {
            Log::error('[ADMIN][SPLIT] Erro ao cancelar split', [
                'split_id' => $split->id,
                'error' => $e->getMessage()
            ]);
            
            return back()->with('error', 'Erro ao cancelar split: ' . $e->getMessage());
        }
    }

    /**
     * Reprocessa um split falhado
     */
    public function retry(SplitPayment $split)
    {
        try {
            if ($split->isFailed()) {
                $split->update(['split_status' => SplitPayment::STATUS_PENDING]);
                
                $result = SplitTrait::executeSplit($split, $split->solicitacao);
                
                if ($result['status'] === 'completed') {
                    return back()->with('success', 'Split reprocessado com sucesso!');
                } else {
                    return back()->with('error', 'Erro ao reprocessar split: ' . $result['message']);
                }
            } else {
                return back()->with('error', 'Apenas splits falhados podem ser reprocessados.');
            }
            
        } catch (\Exception $e) {
            Log::error('[ADMIN][SPLIT] Erro ao reprocessar split', [
                'split_id' => $split->id,
                'error' => $e->getMessage()
            ]);
            
            return back()->with('error', 'Erro ao reprocessar split: ' . $e->getMessage());
        }
    }

    /**
     * Estatísticas de splits
     */
    public function stats(Request $request)
    {
        $startDate = $request->input('start_date', Carbon::now()->startOfMonth());
        $endDate = $request->input('end_date', Carbon::now()->endOfMonth());
        $userId = $request->input('user_id');

        $user = $userId ? User::find($userId) : null;
        $stats = SplitTrait::getSplitStats($user, $startDate, $endDate);

        // Estatísticas por tipo
        $typeStats = SplitPayment::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('split_type, COUNT(*) as count, SUM(split_amount) as total_amount')
            ->groupBy('split_type')
            ->get();

        // Estatísticas por status
        $statusStats = SplitPayment::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('split_status, COUNT(*) as count, SUM(split_amount) as total_amount')
            ->groupBy('split_status')
            ->get();

        // Top usuários por volume de split
        $topUsers = SplitPayment::whereBetween('created_at', [$startDate, $endDate])
            ->where('split_status', SplitPayment::STATUS_COMPLETED)
            ->with('user')
            ->selectRaw('user_id, COUNT(*) as split_count, SUM(split_amount) as total_amount')
            ->groupBy('user_id')
            ->orderBy('total_amount', 'desc')
            ->limit(10)
            ->get();

        return view('admin.splits.stats', compact('stats', 'typeStats', 'statusStats', 'topUsers', 'startDate', 'endDate'));
    }

    /**
     * Exporta splits para CSV
     */
    public function export(Request $request)
    {
        $query = SplitPayment::with(['solicitacao', 'user']);

        // Aplicar filtros
        if ($request->filled('status')) {
            $query->where('split_status', $request->status);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [
                Carbon::parse($request->start_date)->startOfDay(),
                Carbon::parse($request->end_date)->endOfDay()
            ]);
        }

        $splits = $query->orderBy('created_at', 'desc')->get();

        $filename = 'splits_' . date('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($splits) {
            $file = fopen('php://output', 'w');
            
            // Cabeçalho
            fputcsv($file, [
                'ID',
                'Data',
                'Usuário',
                'Email Split',
                'Tipo',
                'Porcentagem',
                'Valor',
                'Status',
                'Processado em',
                'Descrição'
            ]);

            // Dados
            foreach ($splits as $split) {
                fputcsv($file, [
                    $split->id,
                    $split->created_at->format('d/m/Y H:i:s'),
                    $split->user->name ?? 'N/A',
                    $split->split_email,
                    $split->type_formatted,
                    $split->split_percentage . '%',
                    'R$ ' . number_format($split->split_amount, 2, ',', '.'),
                    $split->status_formatted,
                    $split->processed_at ? $split->processed_at->format('d/m/Y H:i:s') : 'N/A',
                    $split->description
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
