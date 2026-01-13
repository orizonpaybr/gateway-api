<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Constants\UserStatus;
use Illuminate\Http\Request;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        Helper::calcularSaldoLiquidoUsuarios();

        $periodo = $request->input('periodo', 'hoje');

        // Filtros de datas
        $dataInicio = null;
        $dataFim = null;

        switch ($periodo) {
            case 'hoje':
                $dataInicio = Carbon::today()->startOfDay();
                $dataFim = Carbon::today()->endOfDay();
                break;

            case 'ontem':
                $dataInicio = Carbon::yesterday()->startOfDay();
                $dataFim = Carbon::yesterday()->endOfDay();
                break;

            case '7dias':
                $dataInicio = Carbon::today()->subDays(6)->startOfDay();
                $dataFim = Carbon::today()->endOfDay();
                break;

            case '30dias':
                $dataInicio = Carbon::today()->subDays(29)->startOfDay();
                $dataFim = Carbon::today()->endOfDay();
                break;

            case 'tudo':
                $dataInicio = Carbon::today()->subYears(100)->startOfDay();
                $dataFim = Carbon::today()->endOfDay();
                break;

            default:
                if (Str::contains($periodo, ':')) {
                    [$start, $end] = explode(':', $periodo);
                    $dataInicio = Carbon::parse($start)->startOfDay();
                    $dataFim = Carbon::parse($end)->endOfDay();
                } else {
                    $dataInicio = Carbon::today()->startOfDay();
                    $dataFim = Carbon::today()->endOfDay();
                }
                break;
        }


        $solicitacoes = Solicitacoes::where('status', 'PAID_OUT');
        $saques = SolicitacoesCashOut::where('status', 'COMPLETED');

        if ($dataInicio && $dataFim) {
            $solicitacoes->whereBetween('date', [$dataInicio, $dataFim]);
            $saques->whereBetween('date', [$dataInicio, $dataFim]);
        }

        // Agora aplique nas variáveis
        $lucroDepositos = (clone $solicitacoes)->sum('taxa_cash_in');
        $lucroSaques = (clone $saques)->sum('taxa_cash_out');
        
        // Calcular taxas pagas aos adquirentes
        $taxasAdquirentesEntradas = 0;
        $taxasAdquirentesSaidas = 0;
        
        // Buscar taxa da XDPag (adquirente padrão)
        $xdpag = \App\Models\XDPag::first();
        if ($xdpag) {
            $taxasAdquirentesEntradas = (clone $solicitacoes)->sum(\DB::raw('amount * ' . ($xdpag->taxa_adquirente_entradas / 100)));
            $taxasAdquirentesSaidas = (clone $saques)->sum(\DB::raw('amount * ' . ($xdpag->taxa_adquirente_saidas / 100)));
        }
        
        $lucro_liquido = ($lucroDepositos + $lucroSaques) - ($taxasAdquirentesEntradas + $taxasAdquirentesSaidas);
  
        $valor_aprovado = (clone $solicitacoes)->sum('amount') + (clone $saques)->sum('amount');
        $transacoes_aprovadas = (clone $solicitacoes)->count() + (clone $saques)->count();

        $cadastros_total = User::whereBetween('created_at', [$dataInicio, $dataFim])->count();
        $cadastros_analise = User::where('status', UserStatus::PENDING)->whereBetween('created_at', [$dataInicio, $dataFim])->count();

        $saques_pendentes = SolicitacoesCashOut::where('status', 'PENDING')->whereBetween('date', [$dataInicio, $dataFim]);
 		$carteiras = User::sum('saldo'); // Removido saldo_bloqueado pois não está sendo usado corretamente
      
        return view("admin.dashboard", compact(
          	"carteiras",
            "solicitacoes",
            "saques",
            "lucro_liquido",
            "lucroDepositos",
            "lucroSaques",
            "valor_aprovado",
            "transacoes_aprovadas",
            "cadastros_total",
            "cadastros_analise",
            "saques_pendentes"
            // outros dados se quiser manter os totais fora do filtro
        ));
    }

    public function exportDashboard(Request $request)
    {
        $periodo = $request->input('periodo', 'hoje');

        // Filtros de datas (mesma lógica do index)
        $dataInicio = null;
        $dataFim = null;

        switch ($periodo) {
            case 'hoje':
                $dataInicio = Carbon::today()->startOfDay();
                $dataFim = Carbon::today()->endOfDay();
                break;
            case 'ontem':
                $dataInicio = Carbon::yesterday()->startOfDay();
                $dataFim = Carbon::yesterday()->endOfDay();
                break;
            case '7dias':
                $dataInicio = Carbon::today()->subDays(6)->startOfDay();
                $dataFim = Carbon::today()->endOfDay();
                break;
            case '30dias':
                $dataInicio = Carbon::today()->subDays(29)->startOfDay();
                $dataFim = Carbon::today()->endOfDay();
                break;
            case 'tudo':
                $dataInicio = Carbon::today()->subYears(100)->startOfDay();
                $dataFim = Carbon::today()->endOfDay();
                break;
            default:
                if (Str::contains($periodo, ':')) {
                    [$start, $end] = explode(':', $periodo);
                    $dataInicio = Carbon::parse($start)->startOfDay();
                    $dataFim = Carbon::parse($end)->endOfDay();
                } else {
                    $dataInicio = Carbon::today()->startOfDay();
                    $dataFim = Carbon::today()->endOfDay();
                }
                break;
        }

        // Buscar dados para export
        $solicitacoes = Solicitacoes::where('status', 'PAID_OUT');
        $saques = SolicitacoesCashOut::where('status', 'COMPLETED');

        if ($dataInicio && $dataFim) {
            $solicitacoes->whereBetween('date', [$dataInicio, $dataFim]);
            $saques->whereBetween('date', [$dataInicio, $dataFim]);
        }

        $lucroDepositos = (clone $solicitacoes)->sum('taxa_cash_in');
        $lucroSaques = (clone $saques)->sum('taxa_cash_out');
        
        // Calcular taxas pagas aos adquirentes
        $taxasAdquirentesEntradas = 0;
        $taxasAdquirentesSaidas = 0;
        
        // Buscar taxa da XDPag (adquirente padrão)
        $xdpag = \App\Models\XDPag::first();
        if ($xdpag) {
            $taxasAdquirentesEntradas = (clone $solicitacoes)->sum(\DB::raw('amount * ' . ($xdpag->taxa_adquirente_entradas / 100)));
            $taxasAdquirentesSaidas = (clone $saques)->sum(\DB::raw('amount * ' . ($xdpag->taxa_adquirente_saidas / 100)));
        }
        
        $lucro_liquido = ($lucroDepositos + $lucroSaques) - ($taxasAdquirentesEntradas + $taxasAdquirentesSaidas);
        $valor_aprovado = (clone $solicitacoes)->sum('amount') + (clone $saques)->sum('amount');
        $transacoes_aprovadas = (clone $solicitacoes)->count() + (clone $saques)->count();
        $cadastros_total = User::whereBetween('created_at', [$dataInicio, $dataFim])->count();
        $cadastros_analise = User::where('status', UserStatus::PENDING)->whereBetween('created_at', [$dataInicio, $dataFim])->count();
        $saques_pendentes = SolicitacoesCashOut::where('status', 'PENDING')->whereBetween('date', [$dataInicio, $dataFim])->sum('amount');
        $carteiras = User::sum('saldo'); // Removido saldo_bloqueado pois não está sendo usado corretamente

        $filename = 'dashboard_' . $periodo . '_' . date('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($carteiras, $lucro_liquido, $lucroDepositos, $lucroSaques, $valor_aprovado, $transacoes_aprovadas, $cadastros_total, $cadastros_analise, $saques_pendentes, $dataInicio, $dataFim) {
            $file = fopen('php://output', 'w');
            
            // Cabeçalhos
            fputcsv($file, [
                'Métrica',
                'Valor',
                'Período'
            ]);

            // Dados
            fputcsv($file, [
                'Saldo em Carteiras',
                'R$ ' . number_format($carteiras, 2, ',', '.'),
                $dataInicio->format('d/m/Y') . ' - ' . $dataFim->format('d/m/Y')
            ]);
            
            fputcsv($file, [
                'Lucro Líquido Total',
                'R$ ' . number_format($lucro_liquido, 2, ',', '.'),
                $dataInicio->format('d/m/Y') . ' - ' . $dataFim->format('d/m/Y')
            ]);
            
            fputcsv($file, [
                'Taxas de Depósitos',
                'R$ ' . number_format($lucroDepositos, 2, ',', '.'),
                $dataInicio->format('d/m/Y') . ' - ' . $dataFim->format('d/m/Y')
            ]);
            
            fputcsv($file, [
                'Taxas de Saques',
                'R$ ' . number_format($lucroSaques, 2, ',', '.'),
                $dataInicio->format('d/m/Y') . ' - ' . $dataFim->format('d/m/Y')
            ]);
            
            fputcsv($file, [
                'Valor Total Aprovado',
                'R$ ' . number_format($valor_aprovado, 2, ',', '.'),
                $dataInicio->format('d/m/Y') . ' - ' . $dataFim->format('d/m/Y')
            ]);
            
            fputcsv($file, [
                'Transações Aprovadas',
                $transacoes_aprovadas,
                $dataInicio->format('d/m/Y') . ' - ' . $dataFim->format('d/m/Y')
            ]);
            
            fputcsv($file, [
                'Usuários Cadastrados',
                $cadastros_total,
                $dataInicio->format('d/m/Y') . ' - ' . $dataFim->format('d/m/Y')
            ]);
            
            fputcsv($file, [
                'Usuários em Análise',
                $cadastros_analise,
                $dataInicio->format('d/m/Y') . ' - ' . $dataFim->format('d/m/Y')
            ]);
            
            fputcsv($file, [
                'Saques Pendentes',
                'R$ ' . number_format($saques_pendentes, 2, ',', '.'),
                $dataInicio->format('d/m/Y') . ' - ' . $dataFim->format('d/m/Y')
            ]);

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
