<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SplitInterno;
use App\Models\SplitInternoExecutado;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SplitInternosController extends Controller
{

    /**
     * Exibe a lista de configurações de splits internos
     */
    public function index(Request $request)
    {
        try {
            // Log de teste
            Log::info('SplitInternosController::index - Acesso iniciado', [
                'user_id' => auth()->id(),
                'permission' => auth()->user() ? auth()->user()->permission : null,
                'request_data' => $request->all()
            ]);

            $query = SplitInterno::with(['usuarioBeneficiario', 'usuarioPagador', 'criadoPorAdmin']);
            
            // Filtros
            if ($request->filled('status')) {
                if ($request->status === 'ativos') {
                    $query->ativos();
                } elseif ($request->status === 'inativos') {
                    $query->where('ativo', false);
                }
            }
            
            if ($request->filled('tipo_taxa')) {
                $query->porTipoTaxa($request->tipo_taxa);
            }
            
            if ($request->filled('usuario_pagador')) {
                $query->porUsuarioPagador($request->usuario_pagador);
            }
            
            $configuracoes = $query->orderBy('created_at', 'desc')->paginate(20);
            
            // Estatísticas gerais
            $estatisticas = $this->obterEstatisticasGerais();
            
            Log::info('SplitInternosController::index - Configurações encontradas', [
                'total' => $configuracoes->total(),
                'count' => $configuracoes->count()
            ]);
            
            return view('admin.splits-internos.index', compact('configuracoes', 'estatisticas'));
            
        } catch (\Exception $e) {
            Log::error('[SPLIT INTERNO] Erro ao carregar lista', [
                'error' => $e->getMessage()
            ]);
            
            return redirect()->back()->with('error', 'Erro ao carregar configurações de splits internos.');
        }
    }

    /**
     * Exibe o formulário para criar nova configuração
     */
    public function create()
    {
        $tiposTaxa = [
            SplitInterno::TAXA_DEPOSITO => 'Depósito',
            SplitInterno::TAXA_SAQUE_PIX => 'Saque PIX'
        ];
        
        // Buscar usuários para dropdown
        $usuarios = User::select('id', 'name', 'email', 'user_id')
            ->where('status', 1)
            ->orderBy('name')
            ->get();
            
        return view('admin.splits-internos.create', compact('tiposTaxa', 'usuarios'));
    }

    /**
     * Armazena nova configuração de split interno
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'usuario_pagador_id' => 'required|exists:users,id|different:usuario_beneficiario_id',
                'usuario_beneficiario_id' => 'required|exists:users,id',
                'porcentagem_split' => 'required|numeric|min:0.01|max:100',
                'tipo_taxa' => 'required|in:deposito,saque_pix',
                'ativo' => 'boolean',
                'data_inicio' => 'nullable|date|after_or_equal:today',
                'data_fim' => 'nullable|date|after:data_inicio'
            ], [
                'usuario_pagador_id.different' => 'O usuário pagador deve ser diferente do usuário beneficiário.',
                'porcentagem_split.max' => 'A porcentagem não pode ser maior que 100%.',
                'porcentagem_split.min' => 'A porcentagem deve ser maior que 0%.',
                'data_fim.after' => 'A data de fim deve ser posterior à data de início.'
            ]);

            if ($validator->fails()) {
                return redirect()->back()
                    ->withErrors($validator)
                    ->withInput();
            }

            $configuracao = SplitInterno::criarConfiguracao($request->all(), Auth::id());

            Log::info('[SPLIT INTERNO] Nova configuração criada', [
                'id' => $configuracao->id,
                'admin_id' => Auth::id()
            ]);

            return redirect()->route('admin.splits-internos.index')
                ->with('success', 'Configuração de split interno criada com sucesso!');

        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Exibe uma configuração específica
     */
    public function show(SplitInterno $splitInterno)
    {
        try {
            $splitInterno->load(['usuarioBeneficiario', 'usuarioPagador', 'criadoPorAdmin', 'splitsExecutados']);
            
            // Estatísticas desta configuração
            $estatisticas = [
                'total_executado' => $splitInterno->splitsExecutados()->processados()->sum('valor_split'),
                'quantidade_executados' => $splitInterno->splitsExecutados->count(),
                'quantidade_processados' => $splitInterno->splitsExecutados->where('status', 'processado')->count(),
                'quantidade_falhados' => $splitInterno->splitsExecutados->where('status', 'falhado')->count()
            ];
            
            return view('admin.splits-internos.show', compact('splitInterno', 'estatisticas'));
            
        } catch (\Exception $e) {
            Log::error('[SPLIT INTERNO] Erro ao carregar configuração', [
                'id' => $splitInterno->id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->back()->with('error', 'Erro ao carregar configuração.');
        }
    }

    /**
     * Ativa/desativa uma configuração
     */
    public function toggleStatus(SplitInterno $splitInterno)
    {
        try {
            $splitInterno->update([
                'ativo' => !$splitInterno->ativo
            ]);
            
            $status = $splitInterno->ativo ? 'ativada' : 'desativada';
            
            Log::info('[SPLIT INTERNO] Configuração alterada', [
                'id' => $splitInterno->id,
                'novo_status' => $status,
                'admin_id' => Auth::id()
            ]);
            
            return redirect()->back()
                ->with('success', "Configuração {$status} com sucesso!");
            
        } catch (\Exception $e) {
            Log::error('[SPLIT INTERNO] Erro ao alterar status', [
                'id' => $splitInterno->id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->back()->with('error', 'Erro ao alterar status da configuração.');
        }
    }

    /**
     * Remove uma configuração
     */
    public function destroy(SplitInterno $splitInterno)
    {
        try {
            // Verificar se há splits executados
            if ($splitInterno->splitsExecutados()->count() > 0) {
                return redirect()->back()
                    ->with('error', 'Não é possível excluir configurações que já tiveram splits executados.');
            }
            
            $splitInterno->delete();
            
            Log::info('[SPLIT INTERNO] Configuração removida', [
                'id' => $splitInterno->id,
                'admin_id' => Auth::id()
            ]);
            
            return redirect()->route('admin.splits-internos.index')
                ->with('success', 'Configuração removida com sucesso!');
                
        } catch (\Exception $e) {
            Log::error('[SPLIT INTERNO] Erro ao remover configuração', [
                'id' => $splitInterno->id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->back()->with('error', 'Erro ao remover configuração.');
        }
    }

    /**
     * Exibe histórico de splits executados
     */
    public function historico(Request $request)
    {
        try {
            $query = SplitInternoExecutado::with([
                'splitInterno.usuarioBeneficiario',
                'splitInterno.usuarioPagador',
                'usuarioBeneficiario',
                'usuarioPagador',
                'solicitacao'
            ]);
            
            // Filtros
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            
            if ($request->filled('usuario_beneficiario')) {
                $query->porBeneficiario($request->usuario_beneficiario);
            }
            
            if ($request->filled('usuario_pagador')) {
                $query->porPagador($request->usuario_pagador);
            }
            
            if ($request->filled('data_inicio')) {
                $query->whereDate('created_at', '>=', $request->data_inicio);
            }
            
            if ($request->filled('data_fim')) {
                $query->whereDate('created_at', '<=', $request->data_fim);
            }
            
            $splitsExecutados = $query->orderBy('created_at', 'desc')->paginate(20);
            
            // Estatísticas do período
            $estatisticas = $this->obterEstatisticasPeriodo($request);
            
            return view('admin.splits-internos.historico', compact('splitsExecutados', 'estatisticas'));
            
        } catch (\Exception $e) {
            Log::error('[SPLIT INTERNO] Erro ao carregar histórico', [
                'error' => $e->getMessage()
            ]);
            
            return redirect()->back()->with('error', 'Erro ao carregar histórico de splits internos.');
        }
    }

    /**
     * Busca usuário por email ou nome para AJAX
     */
    public function buscarUsuario(Request $request)
    {
        try {
            $termo = $request->get('q', '');
            
            if (strlen($termo) < 2) {
                return response()->json([]);
            }
            
            $usuarios = User::select('id', 'name', 'email', 'user_id')
                ->where('status', 1)
                ->where(function($query) use ($termo) {
                    $query->where('name', 'ILIKE', "%{$termo}%")
                          ->orWhere('email', 'ILIKE', "%{$termo}%");
                })
                ->limit(10)
                ->get();
                
            $resultado = $usuarios->map(function($usuario) {
                return [
                    'id' => $usuario->id,
                    'text' => "{$usuario->name} ({$usuario->email}) - {$usuario->user_id}",
                    'name' => $usuario->name,
                    'email' => $usuario->email,
                    'user_id' => $usuario->user_id
                ];
            });
            
            return response()->json($resultado);
            
        } catch (\Exception $e) {
            Log::error('[SPLIT INTERNO] Erro na busca de usuário', [
                'termo' => $request->get('q'),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([]);
        }
    }

    /**
     * Obtém estatísticas gerais do sistema de splits internos
     */
    private function obterEstatisticasGerais(): array
    {
        try {
            return [
                'total_configuracoes_ativas' => SplitInterno::ativos()->count(),
                'total_splits_executados' => SplitInternoExecutado::processados()->count(),
                'total_valor_distribuido' => SplitInternoExecutado::processados()->sum('valor_split'),
                'configuracoes_por_tipo' => [
                    'deposito' => SplitInterno::ativos()->porTipoTaxa(SplitInterno::TAXA_DEPOSITO)->count(),
                    'saque_pix' => SplitInterno::ativos()->porTipoTaxa(SplitInterno::TAXA_SAQUE_PIX)->count()
                ],
                'movimento_mes_atual' => SplitInternoExecutado::processados()
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->sum('valor_split')
            ];
            
        } catch (\Exception $e) {
            Log::error('[SPLIT INTERNO] Erro ao calcular estatísticas gerais', [
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }

    /**
     * Obtém estatísticas de um período específico
     */
    private function obterEstatisticasPeriodo(Request $request): array
    {
        try {
            $query = SplitInternoExecutado::processados();
            
            if ($request->filled('data_inicio')) {
                $query->whereDate('created_at', '>=', $request->data_inicio);
            }
            
            if ($request->filled('data_fim')) {
                $query->whereDate('created_at', '<=', $request->data_fim);
            }
            
            return [
                'total_executados' => $query->count(),
                'total_valor_splits' => $query->sum('valor_split'),
                'total_valor_taxas' => $query->sum('valor_taxa_original'),
                'porcentagem_media' => $query->avg('porcentagem_aplicada') ?? 0,
                'status_breakdown' => [
                    'processados' => SplitInternoExecutado::where('status', 'processado')->count(),
                    'falhados' => SplitInternoExecutado::where('status', 'falhado')->count(),
                    'pendentes' => SplitInternoExecutado::where('status', 'pendente')->count()
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('[SPLIT INTERNO] Erro ao calcular estatísticas de período', [
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }
}