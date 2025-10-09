<?php

namespace App\Http\Controllers\Admin\Transacoes;

use App\Http\Controllers\Controller;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;

class SaidaController extends Controller
{
    public function index()
    {
        $saldoBaixo = 5;
        $users = User::all();

        $totalSaqueAprovado = SolicitacoesCashOut::where('status', 'COMPLETED')
            ->sum('cash_out_liquido');

        $totalDepositosAprovado = Solicitacoes::where('status', 'PAID_OUT')
            ->sum('deposito_liquido');


        $totalSaquePendente = SolicitacoesCashOut::where('status', 'PENDING')
            ->sum('cash_out_liquido');

        $saldoliquido = $totalSaqueAprovado - $totalDepositosAprovado ?? 0;
        return view("admin.transacoes.criarsaida", compact(
            "users",
            "saldoBaixo",
            "totalSaqueAprovado",
            "totalSaquePendente",
            "saldoliquido",

        ));
    }

    public function addsaida(Request $request)
    {

        $valor = floatval($request->valor);
        $user_id = $request->user_id;

        $user = User::where('user_id', $user_id)->first();
        $date = date('Y-m-d H:i:s');

        $uuid = Str::uuid()->toString();
        $idTransaction = str_replace('-', '', $uuid);

        $data = [
            "user_id" => $user_id,
            "externalreference" => env('APP_NAME') . '_' . uniqid(),
            "amount" => $valor,
            "beneficiaryname" => $user->name,
            "beneficiarydocument" => $user->cpf_cnpj,
            "pix" => $user->cpf_cnpj,
            "pixkey" => "CPF",
            "date" => $date,
            "status" => "COMPLETED",
            "type" => "PIX",
            "idTransaction" => $idTransaction,
            "taxa_cash_out" => $user->taxa_cash_out,
            "cash_out_liquido" => $valor,
            "end_to_end" => env('APP_NAME') . "_" . uniqid(),
        ];

        SolicitacoesCashOut::create($data);
        return redirect()->intended(route('admin.transacoes.saidas', absolute: false))->with('success', "Transação criada com sucesso!");
    }
}
