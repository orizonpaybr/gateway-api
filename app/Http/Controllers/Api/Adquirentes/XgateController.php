<?php

namespace App\Http\Controllers\Api\Adquirentes;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\CheckoutOrders;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\Transactions;
use App\Models\User;
use App\Services\PushNotificationService;
use App\Traits\UtmfyTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Helpers\SecureLog;

class XgateController extends Controller
{
    private $pushService;

    public function __construct(PushNotificationService $pushService)
    {
        $this->pushService = $pushService;
    }

    public function callback(Request $request)
    {
        $data = $request->all();
        SecureLog::callback('XGATE', 'CALLBACK', $data);

        switch ($data['operation']) {
            case 'DEPOSIT':
                $idTransaction = $data['id'];
                if ($idTransaction && $data['status'] == "PAID") {
                    $cashin = Solicitacoes::where('idTransaction', $idTransaction)->first();
                    if (!$cashin || $cashin->status != "WAITING_FOR_APPROVAL") {
                        return response()->json(['status' => false]);
                    }

                    $updated_at = Carbon::now();
                    $cashin->update(['status' => 'PAID_OUT', 'updated_at' => $updated_at]);

                    $user = User::where('user_id', $cashin->user_id)->first();
                    Helper::incrementAmount($user, $cashin->deposito_liquido, 'saldo');
                    Helper::calculaSaldoLiquido($user->user_id);

                    // Notificação será enviada automaticamente pelo Observer (SolicitacoesObserver)

                    if (isset($user->gerente_id) && !is_null($user->gerente_id)) {
                        $gerente = User::where('id', $user->gerente_id)->first();
                        $gerente_porcentagem = $gerente->gerente_percentage;

                        $valor = (float) $cashin->taxa_cash_in * (float) $gerente_porcentagem / 100;

                        Transactions::create([
                            'user_id' => $user->user_id,
                            'gerente_id' => $user->gerente_id,
                            'solicitacao_id' => $cashin->id,
                            'comission_value' => $valor,
                            'transaction_percent' => $cashin->taxa_cash_in,
                            'comission_percent' => $gerente_porcentagem,
                        ]);

                        Helper::incrementAmount($gerente, $valor, 'saldo');
                        Helper::calculaSaldoLiquido($gerente->user_id);
                        
                        // Enviar notificação de comissão ao gerente
                        $this->pushService->sendCommissionNotification(
                            $gerente->user_id,
                            $valor,
                            "Comissão de gerente"
                        );
                    }


                    $order = CheckoutOrders::where('idTransaction', $idTransaction)->first();
                    if ($order) {
                        $order->update(['status' => 'pago']);
                        if (!is_null($user->webhook_url) && in_array('pago', (array) $user->webhook_endpoint)) {
                            Http::withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                                ->post($user->webhook_url, [
                                    'nome' => $order->name,
                                    'cpf' => preg_replace('/\D/', '', $order->cpf),
                                    'telefone' => preg_replace('/\D/', '', $order->telefone),
                                    'email' => $order->email,
                                    'status' => 'pago'
                                ]);
                        }
                    }

                    if (!is_null($user->integracao_utmfy)) {
                        $payload = [
                            'name' => $cashin['client_name'],
                            'email' => $cashin['client_email'],
                            'phone' => $cashin['client_telefone'],
                            'cpf' => $cashin['client_document'],
                            'value' => $cashin->amount
                        ];
                        $ip = $request->header('X-Forwarded-For') ?
                            $request->header('X-Forwarded-For') : ($request->header('CF-Connecting-IP') ?
                                $request->header('CF-Connecting-IP') :
                                $request->ip());
                        $msg = "PIX Pago " . env('APP_NAME');
                        UtmfyTrait::gerarUTM('PIX', 'paid', $cashin, $user->integracao_utmfy, $ip, $msg);
                    }

                    if ($cashin->callback && $cashin->callback != 'web') {
                        $payload = [
                            "status"            => "paid",
                            "idTransaction"     => $cashin->idTransaction,
                            "typeTransaction"   => "PIX"
                        ];

                        Http::withHeaders([
                            'Content-Type' => 'application/json',
                            'accept' => 'application/json'
                        ])->post($cashin->callback, $payload);

                        Log::debug("[PIX-IN] Send Callback: Para $cashin->callback -> Enviando...");
                        if ($cashin->callback && $cashin->callback != 'web') {
                            $payload = [
                                "status"            => "paid",
                                "idTransaction"     => $cashin->idTransaction,
                                "typeTransaction"   => "PIX"
                            ];

                            Http::withHeaders([
                                'Content-Type' => 'application/json',
                                'accept' => 'application/json'
                            ])->post($cashin->callback, $payload);

                            $success = 'paid';
                            return response()->json(['status' => $success]);
                        } else {
                            $order = CheckoutOrders::where('idTransaction', $idTransaction)->first();
                            if ($order) {
                                $order->update(['status' => 'pago']);
                                if (!is_null($user->webhook_url) && in_array('pago', (array) $user->webhook_endpoint)) {
                                    Http::withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                                        ->post($user->webhook_url, [
                                            'nome' => $order->name,
                                            'cpf' => preg_replace('/\D/', '', $order->cpf),
                                            'telefone' => preg_replace('/\D/', '', $order->telefone),
                                            'email' => $order->email,
                                            'status' => 'pago'
                                        ]);
                                }
                            }
                        }
                    }
                }
                break;
            case 'WITHDRAW':
                $idTransaction = $data['id'];
                if ($data['status'] == "PAID") {
                    $cashout = SolicitacoesCashOut::where('idTransaction', $idTransaction)->first();
                    if (!$cashout || $cashout->status != "PENDING") {
                        return response()->json(['status' => false]);
                    }

                    $cashout->update(['status' => 'COMPLETED']);
                    $user = User::where('user_id', $cashout->user_id)->first();

                    Helper::decrementAmount($user, $request->amount, 'valor_saque_pendente');

                    if ($cashout->callback && $cashout->callback != 'web') {
                        $payload = [
                            "status"            => "paid",
                            "idTransaction"     => $cashout->idTransaction,
                            "typeTransaction"   => "PAYMENT"
                        ];

                        $sendcallback = Http::withHeaders([
                            'Content-Type' => 'application/json',
                            'accept' => 'application/json'
                        ])->post($cashout->callback, $payload);

                        Log::debug("[PIX-OUT] Send Callback: Para $cashout->callback -> Enviando...");
                        if ($cashout->callback && $cashout->callback != 'web') {
                            $payload = [
                                "status"            => "paid",
                                "idTransaction"     => $cashout->idTransaction,
                                "typeTransaction"   => "PAYMENT"
                            ];

                            Http::withHeaders([
                                'Content-Type' => 'application/json',
                                'accept' => 'application/json'
                            ])->post($cashout->callback, $payload);
                        }
                    }
                } elseif ($data['status'] == "ERROR" || $data['status'] == "REJECTED") {
                    $cashout = SolicitacoesCashOut::where('idTransaction', $idTransaction)->first();

                    $message = 'Erro na Adquirencia.';
                    $cashout->update(['status' => 'CANCELLED', 'descricao_externa' => $message]);

                    if ($cashout->callback && $cashout->callback != 'web') {
                        $payload = [
                            "status"            => "canceled",
                            "idTransaction"     => $cashout->idTransaction,
                            "typeTransaction"   => "PAYMENT"
                        ];

                        $sendcallback = Http::withHeaders([
                            'Content-Type' => 'application/json',
                            'accept' => 'application/json'
                        ])->post($cashout->callback, $payload);

                        Log::debug("[PIX-OUT] Send Callback: Para $cashout->callback -> Enviando...");
                        if ($cashout->callback && $cashout->callback != 'web') {
                            $payload = [
                                "status"            => "canceled",
                                "idTransaction"     => $cashout->idTransaction,
                                "typeTransaction"   => "PAYMENT"
                            ];

                            Http::withHeaders([
                                'Content-Type' => 'application/json',
                                'accept' => 'application/json'
                            ])->post($cashout->callback, $payload);
                        }
                    }
                }
                break;
        }

        return response()->json([], 200);
    }
}
