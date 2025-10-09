<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SolicitacoesCashOut;
use App\Models\{Adquirente, User};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Helpers\Helper;
use App\Traits\{CashtimeTrait, MercadoPagoTrait, EfiTrait, XgateTrait, WitetecTrait, XDPagTrait, PixupTrait, BSPayTrait, AsaasTrait, WooviTrait, PrimePay7Trait};

class SaquesController extends Controller
{
    public function index(Request $request)
    {
        $limit = 10;

        // Página atual
        $page = $request->input('page', 1);
        $offset = ($page - 1) * $limit;

        $totalRecords = DB::table('solicitacoes_cash_out')
            ->where('descricao_transacao', "WEB")
            ->where('status', 'PENDING')->count();
        $totalPages = ceil($totalRecords / $limit);

        // Consultar os registros com paginação
        $saques = DB::table('solicitacoes_cash_out')
            ->where('descricao_transacao', "WEB")
            ->where('status', 'PENDING')
            ->orderByDesc('id')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return view("admin.aprovar-saques", compact('saques', 'page', 'totalRecords', 'totalPages'));
    }

    public function aprovar($id, Request $request)
    {
        if (auth()->user()->permission != 3) {
            return back()->with("error", "Usuário sem permissões.");
        }
      
    
        $default = Helper::adquirenteDefault();
        if (!$default) {
            return back()->with("error", "Nenhum adquirente configurado.");
        }
        switch ($default) {
            case 'cashtime':
                return CashtimeTrait::liberarSaqueManual($id);
                break;
            case 'mercadopago':
            case 'pagarme':
                return MercadoPagoTrait::liberarSaqueManual($id);
                break;
            case 'efi':
                return EfiTrait::liberarSaqueManual($id);
                break;
            case 'xgate':
                return XgateTrait::liberarSaqueManual($id);
            break;
            case 'witetec':
                return WitetecTrait::liberarSaqueManualWitetec($id);
            break;
            case 'xdpag':
                return XDPagTrait::liberarSaqueManual($id);
            break;
            case 'pixup':
                return PixupTrait::liberarSaqueManual($id);
            break;
            case 'bspay':
                return BSPayTrait::liberarSaqueManual($id);
            break;
            case 'asaas':
                return AsaasTrait::liberarSaqueManual($id);
            break;
            case 'woovi':
                return WooviTrait::liberarSaqueManual($id);
            break;
            case 'primepay7':
                return PrimePay7Trait::liberarSaqueManual($id);
            break;
        }
    }

    public function rejeitar($id, Request $request)
    {
        if (auth()->user()->permission != 3) {
            return back()->with("error", "Usuário sem permissões.");
        }

        $saque = SolicitacoesCashOut::where('id', $id)->first();
        if (!$saque) {
            return back()->with("error", "Solicitação de saque não encontrado.");
        }

        $saque->update(['status' => 'CANCELLED']);
        $user = User::where('user_id', $saque->user_id)->first();
        if ($user) {
            $user->increment('transacoes_recused', 1);
            $user->decrement('saldo_bloqueado', $saque->amount);
            $user->save();

            Helper::calculaSaldoLiquido($user->user_id);
        } else {
            \Log::error("Usuário não encontrado ao rejeitar o saque ID: " . $saque->id);
        }

        return back()->with('success', 'Solicitação rejeitada com sucesso.');
    }
}
