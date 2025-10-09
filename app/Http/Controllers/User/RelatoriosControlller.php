<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\SolicitacoesCashOut;
use App\Models\Solicitacoes;
use App\Models\App;
use Carbon\Carbon;
use Illuminate\Support\Str;

class RelatoriosControlller extends Controller
{
    public function pixentrada(Request $request)
    {
        $userId = Auth::user()->user_id;

        $periodo = $request->input('periodo');
        $dataInicio = null;
        $dataFim = null;

        switch ($periodo) {
            case 'hoje':
                $dataInicio = Carbon::today()->toDateString();
                $dataFim = Carbon::today()->toDateString();
                break;

            case 'ontem':
                $dataInicio = Carbon::yesterday()->toDateString();
                $dataFim = Carbon::yesterday()->toDateString();
                break;

            case '7dias':
                $dataInicio = Carbon::today()->subDays(6)->toDateString();
                $dataFim = Carbon::today()->toDateString();
                break;

            case '30dias':
                $dataInicio = Carbon::today()->subDays(29)->toDateString();
                $dataFim = Carbon::today()->toDateString();
                break;

            case 'tudo':
                // Sem filtro de data
                break;

            case 'personalizado':
                $person = explode(':', $periodo);
                $dataInicio = $person[0];
                $dataFim = $person[1];
                break;

            default:
                if (Str::contains($periodo, ':')) {
                    $person = explode(':', $periodo);
                    $dataInicio = $person[0] ?? null;
                    $dataFim = $person[1] ?? null;
                } else {
                    $dataInicio = Carbon::today()->toDateString();
                    $dataFim = Carbon::today()->toDateString();
                }
                break;
        }

        $buscar = $request->input('buscar');
        $statusFilter = $request->input('status', '');
        
        // Sanitizar entrada de busca para prevenir SQL injection
        if ($buscar) {
            $buscar = preg_replace('/[^a-zA-Z0-9\s@._-]/', '', $buscar);
            $buscar = trim($buscar);
            $buscar = substr($buscar, 0, 100); // Limitar tamanho
        }

        $transactions = DB::table('solicitacoes')
            ->where('user_id', $userId)
            ->when($statusFilter, function ($query) use ($statusFilter) {
                return $query->where('status', $statusFilter);
            })
            ->when($dataInicio && $dataFim, function ($query) use ($dataInicio, $dataFim) {
                // Se as datas são iguais, usar whereDate para melhor performance
                if ($dataInicio === $dataFim) {
                    return $query->whereDate('date', $dataInicio);
                }
                // Caso contrário, usar whereBetween com datetime completo
                return $query->whereBetween('date', [$dataInicio . ' 00:00:00', $dataFim . ' 23:59:59']);
            })
            ->when($buscar, function ($query) use ($buscar) {
                return $query->where(function ($q) use ($buscar) {
                    $q->where('client_name', 'like', "%{$buscar}%")
                        ->orWhere('idTransaction', 'like', "%{$buscar}%")
                        ->orWhere('client_email', 'like', "%{$buscar}%")
                        ->orWhere('client_document', 'like', "%{$buscar}%");
                });
            })
            ->orderByDesc('date')
            ->get();

        // Calcular faturamento apenas com transações aprovadas
        $faturamentoQuery = DB::table('solicitacoes')
            ->where('user_id', $userId)
            ->when($statusFilter, function ($query) use ($statusFilter) {
                return $query->where('status', $statusFilter);
            }, function ($query) {
                return $query->whereIn('status', ['PAID_OUT', 'COMPLETED']);
            })
            ->when($dataInicio && $dataFim, function ($query) use ($dataInicio, $dataFim) {
                if ($dataInicio === $dataFim) {
                    return $query->whereDate('date', $dataInicio);
                }
                return $query->whereBetween('date', [$dataInicio . ' 00:00:00', $dataFim . ' 23:59:59']);
            });

        $faturamento = $faturamentoQuery->sum('amount');
        $totalTaxas = $faturamentoQuery->sum('taxa_cash_in');
        $valorLiquido = $faturamento - $totalTaxas;

        // Buscar configurações de personalização de relatórios
        $settings = App::first();
        
        return view("profile.pixentrada", compact('transactions', 'faturamento', 'totalTaxas', 'valorLiquido', 'statusFilter', 'settings'));
    }

    public function pixsaida(Request $request)
    {
        $userId = Auth::user()->user_id;

        $periodo = $request->input('periodo');
        $dataInicio = null;
        $dataFim = null;

        switch ($periodo) {
            case 'hoje':
                $dataInicio = Carbon::today()->toDateString();
                $dataFim = Carbon::today()->toDateString();
                break;

            case 'ontem':
                $dataInicio = Carbon::yesterday()->toDateString();
                $dataFim = Carbon::yesterday()->toDateString();
                break;

            case '7dias':
                $dataInicio = Carbon::today()->subDays(6)->toDateString();
                $dataFim = Carbon::today()->toDateString();
                break;

            case '30dias':
                $dataInicio = Carbon::today()->subDays(29)->toDateString();
                $dataFim = Carbon::today()->toDateString();
                break;

            case 'tudo':
                // Sem filtro de data
                break;

            case 'personalizado':
                $person = explode(':', $periodo);
                $dataInicio = $person[0];
                $dataFim = $person[1];
                break;

            default:
                if (Str::contains($periodo, ':')) {
                    $person = explode(':', $periodo);
                    $dataInicio = $person[0] ?? null;
                    $dataFim = $person[1] ?? null;
                } else {
                    $dataInicio = Carbon::today()->toDateString();
                    $dataFim = Carbon::today()->toDateString();
                }
                break;
        }

        $buscar = $request->input('buscar');
        $statusFilter = $request->input('status', '');

        $transactions = DB::table('solicitacoes_cash_out')
            ->where('user_id', $userId)
            ->when($statusFilter, function ($query) use ($statusFilter) {
                return $query->where('status', $statusFilter);
            })
            ->when($dataInicio && $dataFim, function ($query) use ($dataInicio, $dataFim) {
                // Se as datas são iguais, usar whereDate para melhor performance
                if ($dataInicio === $dataFim) {
                    return $query->whereDate('date', $dataInicio);
                } else {
                    // Para períodos diferentes, usar whereBetween com horário completo
                    $inicio = Carbon::parse($dataInicio)->startOfDay();
                    $fim = Carbon::parse($dataFim)->endOfDay();
                    return $query->whereBetween('date', [$inicio, $fim]);
                }
            })
            ->when($buscar, function ($query) use ($buscar) {
                return $query->where(function ($q) use ($buscar) {
                    $q->where('beneficiaryname', 'like', "%{$buscar}%")
                        ->orWhere('idTransaction', 'like', "%{$buscar}%")
                        ->orWhere('beneficiarydocument', 'like', "%{$buscar}%");
                });
            })
            ->orderByDesc('date')
            ->get();

        // Calcular saídas apenas com transações aprovadas
        $saidas = DB::table('solicitacoes_cash_out')
            ->where('user_id', $userId)
            ->when($statusFilter, function ($query) use ($statusFilter) {
                return $query->where('status', $statusFilter);
            }, function ($query) {
                return $query->whereIn('status', ['PAID_OUT', 'COMPLETED']);
            })
            ->when($dataInicio && $dataFim, function ($query) use ($dataInicio, $dataFim) {
                if ($dataInicio === $dataFim) {
                    return $query->whereDate('date', $dataInicio);
                } else {
                    $inicio = Carbon::parse($dataInicio)->startOfDay();
                    $fim = Carbon::parse($dataFim)->endOfDay();
                    return $query->whereBetween('date', [$inicio, $fim]);
                }
            })
            ->sum('amount');

        // Buscar configurações de personalização de relatórios
        $settings = App::first();
        
        return view("profile.pixsaida", compact('transactions', 'saidas', 'statusFilter', 'settings'));
    }

    public function exportEntradas(Request $request)
    {
        $userId = Auth::user()->user_id;
        $statusFilter = $request->input('status', '');
        
        // Aplicar os mesmos filtros do relatório
        $query = DB::table('solicitacoes')
            ->where('user_id', $userId)
            ->when($statusFilter, function ($query) use ($statusFilter) {
                return $query->where('status', $statusFilter);
            });

        $transactions = $query->orderByDesc('date')->get();

        $filename = 'entradas_' . date('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($transactions) {
            $file = fopen('php://output', 'w');
            
            // Cabeçalhos
            fputcsv($file, [
                'ID',
                'Data',
                'Cliente',
                'Valor',
                'Valor Líquido',
                'Taxa',
                'Status',
                'Método',
                'Documento'
            ]);

            // Dados
            foreach ($transactions as $transaction) {
                fputcsv($file, [
                    $transaction->id,
                    $transaction->date,
                    $transaction->client_name ?? 'N/A',
                    $transaction->amount,
                    $transaction->deposito_liquido,
                    $transaction->taxa_cash_in,
                    $transaction->status,
                    $transaction->method ?? 'N/A',
                    $transaction->client_document ?? 'N/A'
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportSaidas(Request $request)
    {
        $userId = Auth::user()->user_id;
        $statusFilter = $request->input('status', '');
        
        // Aplicar os mesmos filtros do relatório
        $query = DB::table('solicitacoes_cash_out')
            ->where('user_id', $userId)
            ->when($statusFilter, function ($query) use ($statusFilter) {
                return $query->where('status', $statusFilter);
            });

        $transactions = $query->orderByDesc('date')->get();

        $filename = 'saidas_' . date('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($transactions) {
            $file = fopen('php://output', 'w');
            
            // Cabeçalhos
            fputcsv($file, [
                'ID',
                'Data',
                'Beneficiário',
                'Valor',
                'Valor Líquido',
                'Taxa',
                'Status',
                'PIX Key',
                'Documento'
            ]);

            // Dados
            foreach ($transactions as $transaction) {
                fputcsv($file, [
                    $transaction->id,
                    $transaction->date,
                    $transaction->beneficiaryname ?? 'N/A',
                    $transaction->amount,
                    $transaction->cash_out_liquido,
                    $transaction->taxa_cash_out,
                    $transaction->status,
                    $transaction->pix ?? 'N/A',
                    $transaction->beneficiarydocument ?? 'N/A'
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }


    public function consulta(Request $request)
    {
        // Pega os filtros de data
        $dataInicio = $request->input('data_inicio');
        $dataFim = $request->input('data_fim');

        // Configurações de paginação
        $limit = 100; // Número de registros por página
        $page = $request->input('page', 1); // Página atual
        $offset = ($page - 1) * $limit;

        // Consulta para obter a soma filtrada com status COMPLETED
        $filteredQuery = DB::table('solicitacoes_cash_out')
            ->where('status', 'COMPLETED');

        if (!empty($dataInicio) && !empty($dataFim)) {
            $filteredQuery->whereBetween('date', [$dataInicio, $dataFim]);
        }

        $total_cash_out_liquido_filtrado = $filteredQuery->sum('cash_out_liquido');
        $total_cash_out_bruto_filtrada = $filteredQuery->sum('amount');
        $lucro_plataforma_filtrada = $total_cash_out_bruto_filtrada - $total_cash_out_liquido_filtrado;

        // Consulta para obter o número total de registros, ajustando para o filtro de datas
        $countQuery = DB::table('solicitacoes_cash_out')
            ->where('status', 'COMPLETED');

        if (!empty($dataInicio) && !empty($dataFim)) {
            $countQuery->whereBetween('date', [$dataInicio, $dataFim]);
        }

        $totalRecords = $countQuery->count();
        $totalPages = ceil($totalRecords / $limit);

        // Consulta para obter os registros com paginação e filtro de data
        $transactions = DB::table('solicitacoes_cash_out')
            ->where('status', 'COMPLETED')
            ->when($dataInicio && $dataFim, function ($query) use ($dataInicio, $dataFim) {
                return $query->whereBetween('date', [$dataInicio, $dataFim]);
            })
            ->orderByDesc('date')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return view('profile.consulta', compact(
            "transactions",
            "total_cash_out_liquido_filtrado",
            "total_cash_out_bruto_filtrada",
            "lucro_plataforma_filtrada",
            "totalPages",
            "page",
            "dataInicio",
            "dataFim"
        ));
    }

    public function getTransactionDetails($id)
    {
        try {
            // Validar ID
            if (!is_numeric($id) || $id <= 0) {
                return response()->json(['success' => false, 'message' => 'ID inválido'], 400);
            }
            
            $userId = Auth::user()->user_id;
            
            $solicitacao = Solicitacoes::where('id', (int)$id)
                ->where('user_id', $userId)
                ->first();

            if (!$solicitacao) {
                return response()->json(['success' => false, 'message' => 'Transação não encontrada'], 404);
            }

            // Determinar método de pagamento
            $method = 'unknown';
            $methodDetails = [];

            if ($solicitacao->method === 'card') {
                $method = 'card';
                $methodDetails = [
                    'brand' => $solicitacao->card_brand ?? 'N/A',
                    'last_four' => $solicitacao->card_last_four ?? 'N/A',
                    'expiry' => $solicitacao->card_expiry ?? 'N/A'
                ];
            } elseif ($solicitacao->method === 'pix') {
                $method = 'pix';
                $methodDetails = [
                    'qr_code' => $solicitacao->qr_code ?? '',
                    'qr_code_image' => $solicitacao->qr_code_image_url ?? '',
                    'description' => 'PIX Instantâneo'
                ];
            } elseif ($solicitacao->method === 'billet') {
                $method = 'billet';
                $methodDetails = [
                    'billet_url' => $solicitacao->billet_url ?? '',
                    'billet_barcode' => $solicitacao->billet_barcode ?? '',
                    'description' => 'Boleto Bancário'
                ];
            }

            // Calcular taxa
            $taxa = (float)$solicitacao->amount - (float)$solicitacao->deposito_liquido;
            
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $solicitacao->id,
                    'valor' => number_format($solicitacao->amount, 2, ',', '.'),
                    'valor_liquido' => number_format($solicitacao->deposito_liquido, 2, ',', '.'),
                    'taxa' => number_format($taxa, 2, ',', '.'),
                    'cliente' => $solicitacao->client_name ?? 'N/A',
                    'email' => $solicitacao->client_email ?? 'N/A',
                    'documento' => $solicitacao->client_document ?? 'N/A',
                    'status' => $solicitacao->status,
                    'data' => Carbon::parse($solicitacao->date)->format('d/m/Y H:i'),
                    'method' => $method,
                    'method_details' => $methodDetails,
                    'idTransaction' => $solicitacao->idTransaction ?? 'N/A'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar detalhes da transação: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar detalhes da transação: ' . $e->getMessage()
            ], 500);
        }
    }

    public function estornarTransacao($id)
    {
        try {
            // Validar ID
            if (!is_numeric($id) || $id <= 0) {
                return response()->json(['success' => false, 'message' => 'ID inválido'], 400);
            }
            
            $userId = Auth::user()->user_id;
            
            $solicitacao = Solicitacoes::where('id', (int)$id)
                ->where('user_id', $userId)
                ->first();

            if (!$solicitacao) {
                return response()->json(['success' => false, 'message' => 'Transação não encontrada'], 404);
            }

            if ($solicitacao->status !== 'PAID_OUT') {
                return response()->json(['success' => false, 'message' => 'Apenas transações aprovadas podem ser estornadas'], 400);
            }

            // Atualizar status para estornado
            $solicitacao->update([
                'status' => 'REFUNDED',
                'updated_at' => now()
            ]);

            // Decrementar saldo do usuário
            $user = Auth::user();
            if ($user) {
                $user->update(['saldo' => $user->saldo - $solicitacao->deposito_liquido]);
                
                // Log de auditoria
                Log::info('ESTORNO EXECUTADO', [
                    'user_id' => $userId,
                    'transaction_id' => $solicitacao->id,
                    'amount' => $solicitacao->deposito_liquido,
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'timestamp' => now()
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Transação estornada com sucesso. O valor foi removido do seu saldo.'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao estornar transação: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erro ao estornar transação: ' . $e->getMessage()], 500);
        }
    }

    public function cancelarTransacao($id)
    {
        try {
            // Validar ID
            if (!is_numeric($id) || $id <= 0) {
                return response()->json(['success' => false, 'message' => 'ID inválido'], 400);
            }
            
            $userId = Auth::user()->user_id;
            
            $solicitacao = Solicitacoes::where('id', (int)$id)
                ->where('user_id', $userId)
                ->first();

            if (!$solicitacao) {
                return response()->json(['success' => false, 'message' => 'Transação não encontrada'], 404);
            }

            if (!in_array($solicitacao->status, ['PENDING', 'PROCESSING'])) {
                return response()->json(['success' => false, 'message' => 'Apenas transações pendentes ou em processamento podem ser canceladas'], 400);
            }

            // Atualizar status para cancelado
            $solicitacao->update([
                'status' => 'CANCELLED',
                'updated_at' => now()
            ]);
            
            // Log de auditoria
            Log::info('CANCELAMENTO EXECUTADO', [
                'user_id' => $userId,
                'transaction_id' => $solicitacao->id,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'timestamp' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Transação cancelada com sucesso.'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao cancelar transação: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erro ao cancelar transação: ' . $e->getMessage()], 500);
        }
    }
}
