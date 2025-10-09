<?php

namespace App\Http\Controllers\Admin\Financeiro;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Solicitacoes;

class CarteirasController extends Controller
{
    public function index()
    {
        $total_em_carteiras = DB::table('users')
            ->sum('saldo') ?: 0;

        // Consultar o total de solicitações pagas
        $totalPaidOut = DB::table('solicitacoes')
            ->where('status', 'PAID_OUT')
            ->sum('amount') ?: 0;

        // Consultar o total de cash outs completados
        $totalCompleted = DB::table('solicitacoes_cash_out')
            ->where('status', 'COMPLETED')
            ->sum('amount') ?: 0;

        // Calcular o total bruto no gateway
        $totalBrutoGateway = $totalPaidOut - $totalCompleted;

        $usuarios = User::get();

        // Consulta para obter os 3 usuários com mais saldo (faturamento)
        $topUsuarios = DB::table('users')
            ->limit(3)
            ->get();
		
      	$top3Users = Solicitacoes::select(
        'user_id',
        DB::raw('SUM(amount) as total_amount'),
        DB::raw('COUNT(*) as total_paid_out')
        )
        ->where('status', 'PAID_OUT')
        ->where('user_id','!=','admin')
        ->groupBy('user_id')
        ->orderByDesc('total_amount') // << agora ordenando pela soma de amount
        ->limit(3)
        ->get()
        ->map(function ($item) {
            $item->user = User::where('user_id', $item->user_id)->first();
            return $item;
        });




        // Passar as variáveis para a view
        return view('admin.financeiro.carteiras', compact(
            'total_em_carteiras',
            'totalPaidOut',
            'totalCompleted',
            'totalBrutoGateway',
            'usuarios',
            'top3Users',
        ));
    }

    public function exportCsv(Request $request)
    {
        $usuarios = User::all();

        $filename = 'carteiras_' . date('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($usuarios) {
            $file = fopen('php://output', 'w');
            
            // Cabeçalho CSV
            fputcsv($file, [
                'User ID',
                'Email',
                'Telefone',
                'Saldo Atual',
                'Faturamento Total'
            ]);

            // Dados
            foreach ($usuarios as $usuario) {
                $faturamento = $usuario->depositos()->where('status','PAID_OUT')->sum('amount');
                fputcsv($file, [
                    $usuario->user_id ?? '',
                    $usuario->email ?? '',
                    $usuario->telefone ?? '',
                    number_format($usuario->saldo ?? 0, 2, ',', '.'),
                    number_format($faturamento, 2, ',', '.')
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}