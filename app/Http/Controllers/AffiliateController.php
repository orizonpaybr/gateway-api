<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\SplitInterno;
use App\Models\SplitInternoExecutado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AffiliateController extends Controller
{
    /**
     * Painel principal do affiliado - "Meus Referidos"
     */
    public function index()
    {
        try {
            $user = auth()->user();
            
            if (!$user->isAffiliateAtivo()) {
                return redirect()->route('dashboard')->with('error', 'Você não é um affiliado ativo.');
            }

            // Buscar todos os clientes deste affiliado
            $clientes = User::where('affiliate_id', $user->id)
                ->with(['depositos' => function($query) {
                    $query->where('status', 'PAID_OUT');
                }])
                ->paginate(15);

            // Calcular estatísticas
            $totalComissoes = $this->calcularTotalComissoes($user->id);
            $taxaComissao = $user->affiliate_percentage;
            $qtdeReferidos = $clientes->total();
            $qtdeTransacoes = $user->clientesAffiliate()
                ->join('solicitacoes', 'users.id', '=', 'solicitacoes.user_id')
                ->where('solicitacoes.status', 'PAID_OUT')
                ->count();

            // Definir variáveis necessárias para o layout
            $status = auth()->user()->status;
            $permission = auth()->user()->permission;
            
            return view('affiliate.index', compact(
                'user',
                'clientes', 
                'totalComissoes',
                'taxaComissao',
                'qtdeReferidos',
                'qtdeTransacoes',
                'status',
                'permission'
            ));

        } catch (\Exception $e) {
            Log::error('[AFFILIATE] Erro no painel principal', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            
            return redirect()->back()->with('error', 'Erro ao carregar painel de affiliado.');
        }
    }

    /**
     * Calcula o total de comissões recebidas pelo affiliado
     */
    private function calcularTotalComissoes(int $affiliateId): float
    {
        try {
            return SplitInternoExecutado::whereHas('configuracaoSplit', function($query) use ($affiliateId) {
                $query->where('usuario_beneficiario_id', $affiliateId);
            })
            ->where('status', SplitInternoExecutado::STATUS_PROCESSADO)
            ->sum('valor_split');
        } catch (\Exception $e) {
            Log::error('[AFFILIATE] Erro ao calcular comissões', [
                'affiliate_id' => $affiliateId,
                'error' => $e->getMessage()
            ]);
            return 0.00;
        }
    }

    /**
     * Detalhes de um cliente específico
     */
    public function clienteDetalhes($userId)
    {
        try {
            $user = auth()->user();
            
            if (!$user->isAffiliateAtivo()) {
                return redirect()->route('dashboard')->with('error', 'Você não é um affiliado ativo.');
            }

            $cliente = User::where('id', $userId)
                ->where('affiliate_id', $user->id)
                ->with(['depositos' => function($query) {
                    $query->where('status', 'PAID_OUT')->orderBy('created_at', 'desc');
                }])
                ->firstOrFail();

            // Buscar comissões deste cliente específico
            $comissoesRecebidas = SplitInternoExecutado::whereHas('solicitacao', function($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->whereHas('configuracaoSplit', function($query) use ($user) {
                $query->where('usuario_beneficiario_id', $user->id);
            })
            ->where('status', SplitInternoExecutado::STATUS_PROCessADO)
            ->get();

            $totalComissoesCliente = $comissoesRecebidas->sum('valor_split');

            return view('affiliate.cliente-detalhes', compact(
                'cliente',
                'comissoesRecebidas',
                'totalComissoesCliente'
            ));

        } catch (\Exception $e) {
            Log::error('[AFFILIATE] Erro nos detalhes do cliente', [
                'user_id' => auth()->id(),
                'cliente_id' => $userId ?? null,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->back()->with('error', 'Erro ao carregar detalhes do cliente.');
        }
    }

    /**
     * Histórico de comissões do affiliado
     */
    public function historicoComissoes()
    {
        try {
            $user = auth()->user();
            
            if (!$user->isAffiliateAtivo()) {
                return redirect()->route('dashboard')->with('error', 'Você não é um affiliado ativo.');
            }

            $comissoes = SplitInternoExecutado::whereHas('configuracaoSplit', function($query) use ($user) {
                $query->where('usuario_beneficiario_id', $user->id);
            })
            ->with(['solicitacao', 'pagador'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

            return view('affiliate.historico-comissoes', compact('comissoes'));

        } catch (\Exception $e) {
            Log::error('[AFFILIATE] Erro no histórico de comissões', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            
            return redirect()->back()->with('error', 'Erro ao carregar histórico de comissões.');
        }
    }

    /**
     * Copiar link de indicação
     */
    public function copiarLinkIndicacao()
    {
        try {
            $user = auth()->user();
            
            if (!$user->isAffiliateAtivo()) {
                return response()->json(['success' => false, 'message' => 'Você não é um affiliado ativo.']);
            }

            $link = $user->gerarCodigoAffiliate();
            $linkCompleto = $user->affiliate_link ?: config('app.url') . '/register?ref=' . $link;

            return response()->json([
                'success' => true,
                'link' => $linkCompleto,
                'message' => 'Link copiado com sucesso!'
            ]);

        } catch (\Exception $e) {
            Log::error('[AFFILIATE] Erro ao copiar link', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json(['success' => false, 'message' => 'Erro ao gerar link.']);
        }
    }

    /**
     * Solicitação de saque das comissões
     */
    public function solicitarSaque(Request $request)
    {
        try {
            $user = auth()->user();
            
            if (!$user->isAffiliateAtivo()) {
                return redirect()->route('affiliate.index')->with('error', 'Você não é um affiliado ativo.');
            }

            $totalComissoesDisponiveis = $this->calcularTotalComissoes($user->id);
            
            if ($totalComissoesDisponiveis < 10.00) { // Mínimo de R$ 10,00
                return redirect()->route('affiliate.index')->with('error', 'Valor mínimo para saque é de R$ 10,00.');
            }

            // Aqui você pode integrar com o sistema de saques existente
            // Por exemplo, criando uma solicitação de saque específica para affiliates
            
            Log::info('[AFFILIATE] Solicitação de saque', [
                'affiliate_id' => $user->id,
                'valor' => $totalComissoesDisponiveis
            ]);

            return redirect()->route('affiliate.index')->with('success', 'Solicitação de saque enviada. Valor: R$ ' . number_format($totalComissoesDisponiveis, 2, ',', '.'));

        } catch (\Exception $e) {
            Log::error('[AFFILIATE] Erro na solicitação de saque', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            
            return redirect()->back()->with('error', 'Erro ao processar solicitação de saque.');
        }
    }
}