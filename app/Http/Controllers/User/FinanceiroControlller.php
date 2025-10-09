<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Solicitacoes;
use App\Models\Retiradas;
use App\Models\App;
use App\Models\SolicitacoesCashOut;
use Illuminate\Support\Facades\Auth;
use App\Helpers\Helper;
use App\Services\XGate;
use Illuminate\Support\Facades\Log;

class FinanceiroControlller extends Controller
{
    public function index()
    {
        Log::info('FinanceiroController@index: Iniciando execução.', []);

        try {
            $user_id = Auth::user()->user_id;
            Log::info('FinanceiroController@index: User ID', ['user_id' => $user_id]);

            Helper::calculaSaldoLiquido($user_id);
            Log::info('FinanceiroController@index: Helper::calculaSaldoLiquido executado com sucesso.', []);

            // Consultas
            $solicitacoes = Solicitacoes::where('user_id', $user_id)->orderBy('id', 'desc')->limit(4)->get(['id', 'externalreference', 'amount', 'deposito_liquido', 'client_name', 'client_document', 'client_email', 'date', 'status', 'paymentcode']);
            $totalPaidOut = Solicitacoes::where('user_id', $user_id)->where('status', 'PAID_OUT')->count();
            $totalRequests = Solicitacoes::where('user_id', $user_id)->count();
            $sumAmountPaidOut = Solicitacoes::where('user_id', $user_id)->where('status', 'PAID_OUT')->sum('amount') ?: 0;
            $sumDepositoLiquido = Solicitacoes::where('user_id', $user_id)->where('status', 'PAID_OUT')->sum('deposito_liquido') ?: 0;
            $realDate = Solicitacoes::where('user_id', $user_id)->max('date');
            $sumSaquesAprovados = SolicitacoesCashOut::where('user_id', $user_id)->where('status', 'COMPLETED')->sum('amount') ?: 0;
            $retiradas = Retiradas::where('user_id', $user_id)->where('status', 0)->count();
            $retiradasPendentes = $retiradas > 0;
            $totalDepositoLiquido = Solicitacoes::where('user_id', $user_id)->where('status', 'PAID_OUT')->sum('deposito_liquido');
            $totalSaquesAprovados = SolicitacoesCashOut::where('user_id', $user_id)->where('status', 'COMPLETED')->sum('cash_out_liquido');
            $saldoliquido = (float) $totalDepositoLiquido - (float) $totalSaquesAprovados;
            Log::info('FinanceiroController@index: Consultas ao banco de dados executadas.');

            $saldoBaixo = $saldoliquido < 5;
            $email = Auth::user()->email;

            $app = App::first();
            Log::info('FinanceiroController@index: App::first() executado.');
            
            // Buscar configurações personalizadas do usuário
            $user = Auth::user();
            $taxasPersonalizadas = $user->taxas_personalizadas_ativas ?? false;
            
            // Taxas de saque - usar personalizadas se disponíveis
            if ($taxasPersonalizadas) {
                $taxa_cash_out = $user->taxa_percentual_pix ?? $app->taxa_cash_out_padrao ?? 5;
                $taxa_fixa_padrao_cash_out = $user->taxa_fixa_pix ?? $app->taxa_fixa_padrao_cash_out ?? 0;
                $limite_mensal_pf = $user->limite_mensal_pf ?? $app->limite_saque_mensal ?? 50000;
                $limite_saques_mes = $user->saques_por_cpf ?? 5;
            } else {
                $taxa_cash_out = $app->taxa_cash_out_padrao ?? 5;
                $taxa_fixa_padrao_cash_out = $app->taxa_fixa_padrao_cash_out ?? 0;
                $limite_mensal_pf = $app->limite_saque_mensal ?? 50000;
                $limite_saques_mes = 5; // valor padrão
            }
            
            $taxa_cash_in = $app->taxa_cash_in ?? 5;
            $taxa_fixa_padrao = $app->taxa_fixa_padrao ?? 0;
            Log::info('FinanceiroController@index: Taxas carregadas.');

            auth()->user()->fresh();

            $networks = null;
            $adquirente = Helper::adquirenteDefault();
            Log::info('FinanceiroController@index: Adquirente Default: ' . $adquirente);

            if ($adquirente == 'xgate') {
                Log::info('FinanceiroController@index: Entrando no bloco XGate.');
                $xgate = new XGate();
                Log::info('FinanceiroController@index: XGate instanciado.');
                $networks = $xgate->getNetworks();
                Log::info('FinanceiroController@index: XGate->getNetworks() executado.');
            }

            Log::info('FinanceiroController@index: Preparando para retornar a view.');
            return view("profile.financeiro", compact(
                'taxa_cash_in',
                'taxa_cash_out',
                'taxa_fixa_padrao_cash_out',
                'limite_mensal_pf',
                'limite_saques_mes',
                'taxasPersonalizadas',
                'email',
                'retiradasPendentes',
                "solicitacoes",
                'saldoBaixo',
                "totalPaidOut",
                "totalRequests",
                "sumAmountPaidOut",
                "sumDepositoLiquido",
                "realDate",
                "sumSaquesAprovados",
                "saldoliquido",
                "taxa_fixa_padrao",
                "networks"
            ));
        } catch (\Throwable $e) {
            Log::error('Erro fatal no FinanceiroController@index: ' . $e->getMessage() . ' no arquivo ' . $e->getFile() . ' na linha ' . $e->getLine());
            // Retornar uma view de erro ou abortar pode ser útil aqui
            abort(500, 'Ocorreu um erro interno no servidor.');
        }
    }
}
