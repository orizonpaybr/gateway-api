<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use App\Models\Solicitacoes;
use App\Models\User;
use App\Models\SolicitacoesCashOut;
use Carbon\Carbon;
use App\Helpers\Helper;
use App\Models\App;
use App\Models\CheckoutOrders;
use App\Models\Transactions;
use App\Models\Woovi;
use App\Services\PushNotificationService;
use App\Traits\SplitTrait;
use App\Helpers\SecureLog;

class CallbackController extends Controller
{
    private $pushService;

    public function __construct(PushNotificationService $pushService)
    {
        $this->pushService = $pushService;
    }

    public function callbackDeposit(Request $request)
    {
        $data = $request->all();
        if ($data['status'] != "paid") {
            return response()->json(['status' => false, 'message' => 'Not paid']);
        }

        $cashin = Solicitacoes::where('idTransaction', $data['orderId'])->first();

        if (!$cashin || $cashin->status != "WAITING_FOR_APPROVAL") {
            return response()->json(['status' => false, 'message' => 'Invalid solicitation']);
        }

        $cashin->update(['status' => 'PAID_OUT', 'updated_at' => Carbon::now()]);

        $user = User::where('user_id', $cashin->user_id)->first();
        if ($user) {
            Helper::incrementAmount($user, $cashin->deposito_liquido, 'saldo');
            Helper::calculaSaldoLiquido($user->user_id);

            if (isset($user->gerente_id) && !is_null($user->gerente_id)) {
                $gerente = User::where('id', $user->gerente_id)->first();
                if ($gerente) {
                    $gerente_porcentagem = $gerente->gerente_percentage;
                    $valor = (float)$cashin->taxa_cash_in * (float)$gerente_porcentagem / 100;

                    Transactions::create([
                        'user_id' => $user->user_id,
                        'gerente_id' => $user->gerente_id,
                        'solicitacao_id' => $cashin->id,
                        'comission_value' => $valor,
                        'transaction_percent' => $cashin->taxa_cash_in,
                        'comission_percent' => $gerente_porcentagem,
                    ]);

                    Helper::calculaSaldoLiquido($gerente->user_id);
                }
            }

            $order = CheckoutOrders::where('idTransaction', $data['orderId'])->first();
            if ($order) {
                $order->update(['status' => 'pago']);
                if (!is_null($user->webhook_url) && in_array('pago', (array) $user->webhook_endpoint)) {
                    Http::post($user->webhook_url, [
                        'nome' => $order->name,
                        'cpf' => preg_replace('/\D/', '', $order->cpf),
                        'telefone' => preg_replace('/\D/', '', $order->telefone),
                        'email' => $order->email,
                        'status' => 'pago'
                    ]);
                }
            }
        }

        if ($cashin->callback) {
            $payload = [
                "status" => "paid",
                "idTransaction" => $cashin->idTransaction,
                "typeTransaction" => "PIX"
            ];

            Http::post($cashin->callback, $payload);
            Log::debug("[PIX-IN] Send Callback: Para $cashin->callback -> Enviando...");

            if ($cashin->callback != 'web') {
                return response()->json(['status' => 'paid']);
            }
        }

        return response()->json(['status' => 'success']);
    }

    public function callbackWithdraw(Request $request)

    {
        $data = $request->all();

        SecureLog::callback('GENERIC', 'PIX-OUT', $data);

        // Validação mais rigorosa dos dados do callback
        if (!isset($data['withdrawStatusId']) || !isset($data['id']) || !isset($data['amount'])) {
            Log::warning("[PIX-OUT] Callback inválido - dados obrigatórios ausentes");
            return response()->json(['status' => false, 'message' => 'Dados inválidos']);
        }

        if ($data['withdrawStatusId'] == "Successfull") {
            $cashout = SolicitacoesCashOut::where('idTransaction', $data['id'])->first();

            if (!$cashout || $cashout->status != "PENDING") {
                return response()->json(['status' => false]);
            }

            $cashout->update(['status' => 'COMPLETED', 'updated_at' => $data['updatedAt']]);
            $user = User::where('user_id', $cashout->user_id)->first();
            if ($user) {
                Helper::decrementAmount($user, $data['amount'] ?? $cashout->amount, 'valor_saque_pendente');
            }


            if ($cashout->callback) {
                $payload = [
                    "status"            => "paid",
                    "idTransaction"     => $cashout->idTransaction,
                    "typeTransaction"   => "PAYMENT",
                    "externalId"        => $cashout->externalreference
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
                        "typeTransaction"   => "PAYMENT",
                        "externalId"        => $cashout->externalreference
                    ];

                    Http::withHeaders([
                        'Content-Type' => 'application/json',
                        'accept' => 'application/json'
                    ])->post($cashout->callback, $payload);

                    return response()->json(['status' => 'paid']);
                }
            }
        } elseif (in_array($data['withdrawStatusId'], ["failed", "rejected", "cancelled", "Canceled", "Failed"])) {
            // Falha no saque: marcar como CANCELLED/REJECTED e reverter saldo
            $cashout = SolicitacoesCashOut::where('idTransaction', $data['id'])->first();
            if (!$cashout) {
                return response()->json(['status' => false, 'message' => 'Solicitação não encontrada']);
            }

            $novoStatus = 'CANCELLED';
            $cashout->update(['status' => $novoStatus, 'updated_at' => Carbon::now()]);

            $user = User::where('user_id', $cashout->user_id)->first();
            if ($user) {
                $valorParaReverter = $cashout->amount ?? $cashout->valor ?? 0;
                Helper::incrementAmount($user, $valorParaReverter, 'saldo');
                Helper::calculaSaldoLiquido($user->user_id);
            }

            if ($cashout->callback) {
                $payload = [
                    "status"            => "canceled",
                    "idTransaction"     => $cashout->idTransaction,
                    "typeTransaction"   => "PAYMENT",
                    "externalId"        => $cashout->externalreference
                ];

                Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'accept' => 'application/json'
                ])->post($cashout->callback, $payload);
            }

            return response()->json(['status' => true, 'message' => 'Saque cancelado']);
        }
    }

    public function callbackDepositMercadopago(Request $request)
    {
        $data = $request->all();
        if (isset($data['action']) && $data['action'] == 'payment.updated' && isset($data['data_id'])) {
            $idTransaction = $data['data_id'];
            $status = $request->status;
            if (isset($status) && $status === 'paid') {
                $cashin = Solicitacoes::where('idTransaction', $idTransaction)->first();

                if (!$cashin || $cashin->status != "WAITING_FOR_APPROVAL") {
                    return response()->json(['status' => false]);
                }

                $updated_at = Carbon::now();
                $cashin->update(['status' => 'PAID_OUT', 'updated_at' => $updated_at]);

                $user = User::where('user_id', $cashin->user_id)->first();
                if ($user) {
                    Helper::incrementAmount($user, $cashin->deposito_liquido, 'saldo');
                    Helper::calculaSaldoLiquido($user->user_id);
                }

                $order = CheckoutOrders::where('idTransaction', $data['orderId'])->first();
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

                if ($user && isset($user->gerente_id) && !is_null($user->gerente_id)) {
                    $gerente = User::where('id', $user->gerente_id)->first();
                    if ($gerente) {
                        $gerente_porcentagem = $gerente->gerente_percentage;

                        $valor = (float)$cashin->taxa_cash_in * (float)$gerente_porcentagem / 100;

                        Transactions::create([
                            'user_id' => $user->user_id,
                            'gerente_id' => $user->gerente_id,
                            'solicitacao_id' => $cashin->id,
                            'comission_value' => $valor,
                            'transaction_percent' => $cashin->taxa_cash_in,
                            'comission_percent' => $gerente_porcentagem,
                        ]);

                        Helper::calculaSaldoLiquido($gerente->user_id);
                    }
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
                    return response()->json(['status' => 'paid']);
                } else {
                    $order = CheckoutOrders::where('idTransaction', $data['data_id'])->first();
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

        return response()->json([], 200);
    }

    public function callbackEfi(Request $request)
    {
        $data = $request->all();
        SecureLog::callback('EFI', 'CALLBACK', $data);

        $dados = $data['pix'][0];
        $tipo = isset($dados['tipo']) && $dados['tipo'] == 'SOLICITACAO' ? 'saque' : 'deposito';
        Log::debug('[+] Tipo de callback Efí: ' . $tipo);

        switch ($tipo) {
            case 'deposito':
                $idTransaction = $dados['txid'];
                $existEndToEndId = isset($dados['endToEndId']) && isset($dados['gnExtras']['pagador']);
                if ($existEndToEndId) {
                    $cashin = Solicitacoes::where('idTransaction', $idTransaction)->first();
                    if (!$cashin || $cashin->status != "WAITING_FOR_APPROVAL") {
                        return response()->json(['status' => false]);
                    }

                    $updated_at = Carbon::now();
                    $cashin->update(['status' => 'PAID_OUT', 'updated_at' => $updated_at]);

                    $user = User::where('user_id', $cashin->user_id)->first();
                    if ($user) {
                        Helper::incrementAmount($user, $cashin->deposito_liquido, 'saldo');
                        Helper::calculaSaldoLiquido($user->user_id);
                    }

                    if ($user && isset($user->gerente_id) && !is_null($user->gerente_id)) {
                        $gerente = User::where('id', $user->gerente_id)->first();
                        if ($gerente) {
                            $gerente_porcentagem = $gerente->gerente_percentage;

                            $valor = (float)$cashin->taxa_cash_in * (float)$gerente_porcentagem / 100;

                            Transactions::create([
                                'user_id' => $user->user_id,
                                'gerente_id' => $user->gerente_id,
                                'solicitacao_id' => $cashin->id,
                                'comission_value' => $valor,
                                'transaction_percent' => $cashin->taxa_cash_in,
                                'comission_percent' => $gerente_porcentagem,
                            ]);

                            Helper::calculaSaldoLiquido($gerente->user_id);
                        }
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
                            $order = CheckoutOrders::where('idTransaction', $dados['txid'])->first();
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
            case 'saque':
                $idTransaction = $dados['gnExtras']['idEnvio'];
                if ($dados['status'] == "REALIZADO") {
                    $cashout = SolicitacoesCashOut::where('idTransaction', $idTransaction)->first();
                    if (!$cashout || $cashout->status != "PENDING") {
                        return response()->json(['status' => false]);
                    }

                    $cashout->update(['status' => 'COMPLETED']);
                    $user = User::where('user_id', $cashout->user_id)->first();
                    if ($user) {
                        Helper::decrementAmount($user, $cashout->amount, 'valor_saque_pendente');
                    }

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
                } elseif ($dados['status'] == "NAO_REALIZADO") {
                    $cashout = SolicitacoesCashOut::where('idTransaction', $idTransaction)->first();
                    if ($cashout) {
                        $message = $dados['gnExtras']['erro']['motivo'] ?? 'Erro na Adquirencia.';
                        $cashout->update(['status' => 'CANCELLED', 'descricao_externa' => $message]);

                        // Reverter saldo ao usuário
                        $user = User::where('user_id', $cashout->user_id)->first();
                        if ($user) {
                            $valorParaReverter = $cashout->amount ?? $cashout->valor ?? 0;
                            Helper::incrementAmount($user, $valorParaReverter, 'saldo');
                            Helper::calculaSaldoLiquido($user->user_id);
                        }
                    }

                    if ($cashout && $cashout->callback && $cashout->callback != 'web') {
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

    public function webhookPagarme(Request $request)
    {
        $data = $request->all();
        SecureLog::webhook('PAGARME', 'WEBHOOK', $data);

        $type = $data['type'];
        $status = $data['data']['charges'][0]['last_transaction']['status'];
        $transaction_id = $data['data']['id'];

        Log::debug("[PAGAR.ME] DADOS PRINCIPAIS: " . json_encode(compact('type', 'status', 'transaction_id')));
        Log::debug("[PAGAR.ME] WEBHOOK PIX PAGO?: " . '' . ($type == "order.paid" && $status == "paid"));
        if ($type == "order.paid" && $status == "paid") {
            $cashin = Solicitacoes::where('idTransaction', $transaction_id)->first();
            Log::debug("[PAGAR.ME] SOLICITAÇÃO: " . json_encode($cashin));

            if (!$cashin || $cashin->status != "WAITING_FOR_APPROVAL") {
                return response()->json(['status' => false]);
            }

            $updated_at = Carbon::now();
            $cashin->update(['status' => 'PAID_OUT', 'updated_at' => $updated_at]);

            $user = User::where('user_id', $cashin->user_id)->first();
            if ($user) {
                Helper::incrementAmount($user, $cashin->deposito_liquido, 'saldo');
                Helper::calculaSaldoLiquido($user->user_id);
            }


            if ($user && isset($user->gerente_id) && !is_null($user->gerente_id)) {
                $gerente = User::where('id', $user->gerente_id)->first();
                if ($gerente) {
                    $gerente_porcentagem = $gerente->gerente_percentage;

                    $valor = (float)$cashin->taxa_cash_in * (float)$gerente_porcentagem / 100;

                    Transactions::create([
                        'user_id' => $user->user_id,
                        'gerente_id' => $user->gerente_id,
                        'solicitacao_id' => $cashin->id,
                        'comission_value' => $valor,
                        'transaction_percent' => $cashin->taxa_cash_in,
                        'comission_percent' => $gerente_porcentagem,
                    ]);

                    Helper::calculaSaldoLiquido($gerente->user_id);
                }
            }

            $order = CheckoutOrders::where('idTransaction', $transaction_id)->first();
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

            if ($cashin->callback) {
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

                    return response()->json(['status' => true]);
                }
            }
        }
    }

    public function callbackCard(Request $request)
    {
        $data = $request->all();
        SecureLog::callback('EFI', 'CARD', $data);
    }

    /**
     * Callback do Woovi para notificação de pagamentos
     */
    public function callbackWoovi(Request $request)
    {
        try {
            $data = $request->all();
            SecureLog::webhook('WOOVI', 'CALLBACK', $data);

            // Validar autorização do webhook
            $authorization = $request->get('authorization');
            $woovi = Woovi::first();
            
            if (!$woovi || !$woovi->webhook_secret) {
                Log::error('[WOOVI][CALLBACK]: Woovi não configurado ou webhook_secret não definido');
                return response()->json(['status' => 'error', 'message' => 'Webhook não configurado'], 500);
            }

            if (!$authorization || $authorization !== $woovi->webhook_secret) {
                Log::error('[WOOVI][CALLBACK]: Autorização inválida. Recebido: ' . $authorization);
                return response()->json(['status' => 'error', 'message' => 'Autorização inválida'], 401);
            }

            Log::info('[WOOVI][CALLBACK]: Autorização válida, processando callback', []);

            // Verificar se é uma notificação de cobrança
            $event = $data['event'] ?? '';
            if (!in_array($event, ['charge.completed', 'OPENPIX:CHARGE_COMPLETED'])) {
                Log::info('[WOOVI][CALLBACK]: Evento não é charge.completed ou OPENPIX:CHARGE_COMPLETED, ignorando. Evento recebido: ' . $event, []);
                return response()->json(['status' => 'success', 'message' => 'Evento ignorado']);
            }

            // Verificar se é um evento de teste
            if (isset($data['evento']) && $data['evento'] === 'teste_webhook') {
                Log::info('[WOOVI][CALLBACK]: Evento de teste recebido, retornando sucesso', []);
                return response()->json(['status' => 'success', 'message' => 'Webhook de teste recebido com sucesso']);
            }

            // Verificar se tem os dados da cobrança
            if (!isset($data['charge'])) {
                Log::error('[WOOVI][CALLBACK]: Dados da cobrança não encontrados');
                return response()->json(['status' => 'error', 'message' => 'Dados da cobrança não encontrados'], 400);
            }

            $correlationID = $data['charge']['correlationID'] ?? null;
            $wooviIdentifier = $data['charge']['id'] ?? null;
            $status = $data['charge']['status'] ?? 'unknown';

            Log::info('[WOOVI][CALLBACK]: Processando cobrança', [
                'correlationID' => $correlationID,
                'identifier' => $wooviIdentifier,
                'status' => $status
            ]);

            // Buscar a transação pelo correlationID ou pelo identificador da Woovi
            $transacao = null;
            
            if ($correlationID) {
                $transacao = Solicitacoes::where('idTransaction', $correlationID)
                    ->orWhere('externalreference', $correlationID)
                    ->first();
            }
            
            // Se não encontrou pelo correlationID, tentar pelo identificador da Woovi
            if (!$transacao && $wooviIdentifier) {
                $transacao = Solicitacoes::where('woovi_identifier', $wooviIdentifier)->first();
            }

            if (!$transacao) {
                Log::error('[WOOVI][CALLBACK]: Transação não encontrada para correlationID: ' . $correlationID . ' ou identifier: ' . $wooviIdentifier);
                return response()->json(['status' => 'error', 'message' => 'Transação não encontrada'], 404);
            }

            // Verificar se a transação já foi processada
            if ($transacao->status === 'PAID_OUT') {
                Log::info('[WOOVI][CALLBACK]: Transação já foi processada', ['correlationID' => $correlationID]);
                return response()->json(['status' => 'success', 'message' => 'Transação já processada']);
            }

            // Processar pagamento baseado no status
            if ($status === 'COMPLETED' || $status === 'paid') {
                // Atualizar status da transação
                $transacao->update([
                    'status' => 'PAID_OUT',
                    'updated_at' => Carbon::now()
                ]);

                // Buscar o usuário
                $user = User::where('user_id', $transacao->user_id)->first();
                if ($user) {
                    // Creditar o saldo do usuário
                    Helper::incrementAmount($user, $transacao->deposito_liquido, 'saldo');
                    Helper::calculaSaldoLiquido($user->user_id);

                    // Log específico para depósito
                    \App\Helpers\BalanceLogHelper::logDepositOperation(
                        'DEPOSIT_CREDIT',
                        $user,
                        $transacao->deposito_liquido,
                        [
                            'transacao_id' => $transacao->id,
                            'adquirente' => 'WOOVI',
                            'valor_bruto' => $transacao->amount,
                            'valor_liquido' => $transacao->deposito_liquido,
                            'correlation_id' => $correlationID,
                            'operacao' => 'woovi_callback'
                        ]
                    );

                    Log::info('[WOOVI][CALLBACK]: Saldo creditado para usuário', [
                        'user_id' => $user->user_id,
                        'amount' => $transacao->deposito_liquido
                    ]);

                    // Notificação será enviada automaticamente pelo Observer (SolicitacoesObserver)

                    // Processar splits se configurados
                    if ($transacao->split_email && $transacao->split_percentage) {
                        $splitResults = SplitTrait::processSplits($transacao, $user);
                        Log::info('[WOOVI][CALLBACK]: Splits processados', [
                            'solicitacao_id' => $transacao->id,
                            'split_results' => $splitResults
                        ]);
                    }

                    // Processar comissão do gerente se houver
                    if (isset($user->gerente_id) && !is_null($user->gerente_id)) {
                        $gerente = User::where('id', $user->gerente_id)->first();
                        if ($gerente) {
                            $gerente_porcentagem = $gerente->gerente_percentage;
                            $valor = (float)$transacao->taxa_cash_in * (float)$gerente_porcentagem / 100;

                            Transactions::create([
                                'user_id' => $user->user_id,
                                'gerente_id' => $user->gerente_id,
                                'solicitacao_id' => $transacao->id,
                                'amount' => $valor,
                                'type' => 'gerente_commission',
                                'description' => 'Comissão de gerente - Depósito PIX',
                                'created_at' => Carbon::now(),
                                'updated_at' => Carbon::now()
                            ]);

                            Helper::incrementAmount($gerente, $valor, 'saldo');
                            Log::info('[WOOVI][CALLBACK]: Comissão de gerente creditada', ['amount' => $valor]);
                            
                            // Enviar notificação de comissão ao gerente
                            $this->pushService->sendCommissionNotification(
                                $gerente->user_id,
                                $valor,
                                "Comissão de gerente - Depósito"
                            );
                        }
                    }
                }

                // Atualizar status do pedido de checkout se existir
                $checkoutOrder = CheckoutOrders::where('idTransaction', $correlationID)->first();
                if ($checkoutOrder) {
                    $checkoutOrder->update(['status' => 'pago']);
                    
                    // Enviar webhook se configurado
                    if (!is_null($user->webhook_url) && in_array('pago', (array) $user->webhook_endpoint)) {
                        Http::withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                            ->post($user->webhook_url, [
                                'nome' => $checkoutOrder->name,
                                'cpf' => preg_replace('/\D/', '', $checkoutOrder->cpf),
                                'telefone' => preg_replace('/\D/', '', $checkoutOrder->telefone),
                                'email' => $checkoutOrder->email,
                                'status' => 'pago'
                            ]);
                    }
                    
                    Log::info('[WOOVI][CALLBACK]: Status do pedido de checkout atualizado para pago', ['correlationID' => $correlationID]);
                }

                // ENVIAR WEBHOOK PARA O CASSINO (POSTBACK)
                Log::info('[WOOVI][CALLBACK]: Verificando callback da transação', [
                    'correlationID' => $correlationID,
                    'callback' => $transacao->callback,
                    'callback_not_web' => $transacao->callback != 'web'
                ]);
                
        if ($transacao->callback && $transacao->callback != 'web') {
            $payload = [
                "idTransaction" => $transacao->idTransaction,
                "status" => "paid",
                "typeTransaction" => "PIX",
                "amount" => (float) $transacao->amount,
                "debtor_name" => $transacao->client_name ?? 'Cliente',
                "email" => $transacao->client_email ?? 'cliente@email.com',
                "debtor_document_number" => $transacao->client_document ?? '00000000000',
                "phone" => $transacao->client_telefone ?? '11999999999',
                "created_at" => $transacao->created_at->toISOString(),
                "paid_at" => $transacao->updated_at->toISOString(),
                "split_processed" => $transacao->split_email && $transacao->split_percentage ? true : false,
                "split_amount" => $transacao->split_email && $transacao->split_percentage ? (float) (($transacao->amount * $transacao->split_percentage) / 100) : 0,
                "split_recipient" => $transacao->split_email ?? null
            ];

                    Log::info('[WOOVI][CALLBACK]: Enviando webhook para cassino', [
                        'callback_url' => $transacao->callback,
                        'payload' => $payload
                    ]);

                    try {
                        $response = Http::withHeaders([
                            'Content-Type' => 'application/json',
                            'accept' => 'application/json'
                        ])->timeout(30)->post($transacao->callback, $payload);

                        Log::info('[WOOVI][CALLBACK]: Webhook enviado para cassino', [
                            'callback_url' => $transacao->callback,
                            'status_code' => $response->status(),
                            'response' => $response->body()
                        ]);
                    } catch (\Exception $e) {
                        Log::error('[WOOVI][CALLBACK]: Erro ao enviar webhook para cassino', [
                            'callback_url' => $transacao->callback,
                            'error' => $e->getMessage()
                        ]);
                    }
                } else {
                    Log::warning('[WOOVI][CALLBACK]: Callback não configurado ou é web', [
                        'callback' => $transacao->callback
                    ]);
                }

                Log::info('[WOOVI][CALLBACK]: Pagamento processado com sucesso para transação', ['correlationID' => $correlationID]);
                return response()->json(['status' => 'success', 'message' => 'Pagamento processado com sucesso']);

            } else {
                // Pagamento falhou ou foi cancelado
                $transacao->update([
                    'status' => 'CANCELLED',
                    'updated_at' => Carbon::now()
                ]);

                Log::info('[WOOVI][CALLBACK]: Pagamento cancelado/falhou para transação', ['correlationID' => $correlationID]);
                return response()->json(['status' => 'success', 'message' => 'Status atualizado para cancelado']);
            }

        } catch (\Exception $e) {
            Log::error('[WOOVI][CALLBACK][ERROR]: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Callback do Woovi para notificação de saques (Cash Out)
     */
    public function callbackWooviWithdraw(Request $request)
    {
        try {
            $data = $request->all();
            SecureLog::webhook('WOOVI', 'WITHDRAW_CALLBACK', $data);

            // Validar autorização do webhook
            $authorization = $request->get('authorization');
            $woovi = Woovi::first();
            
            if (!$woovi || !$woovi->webhook_secret) {
                Log::error('[WOOVI][WITHDRAW_CALLBACK]: Woovi não configurado ou webhook_secret não definido');
                return response()->json(['status' => 'error', 'message' => 'Webhook não configurado'], 500);
            }

            if (!$authorization || $authorization !== $woovi->webhook_secret) {
                Log::error('[WOOVI][WITHDRAW_CALLBACK]: Autorização inválida. Recebido: ' . $authorization);
                return response()->json(['status' => 'error', 'message' => 'Autorização inválida'], 401);
            }

            Log::info('[WOOVI][WITHDRAW_CALLBACK]: Autorização válida, processando callback de saque', []);

            // Verificar se é uma notificação de saque
            $event = $data['event'] ?? '';
            if (!in_array($event, ['withdraw.completed', 'OPENPIX:WITHDRAW_COMPLETED', 'OPENPIX:MOVEMENT_CONFIRMED'])) {
                Log::info('[WOOVI][WITHDRAW_CALLBACK]: Evento não é de saque, ignorando. Evento recebido: ' . $event, []);
                return response()->json(['status' => 'success', 'message' => 'Evento ignorado']);
            }

            // Verificar se tem os dados do saque
            if (!isset($data['withdraw']) && !isset($data['movement'])) {
                Log::error('[WOOVI][WITHDRAW_CALLBACK]: Dados do saque não encontrados');
                return response()->json(['status' => 'error', 'message' => 'Dados do saque não encontrados'], 400);
            }

            // Extrair dados do saque (pode vir em 'withdraw' ou 'movement')
            $withdrawData = $data['withdraw'] ?? $data['movement'] ?? [];
            $transactionId = $withdrawData['id'] ?? $withdrawData['transactionId'] ?? null;
            $status = $withdrawData['status'] ?? 'unknown';
            $value = $withdrawData['value'] ?? 0;

            Log::info('[WOOVI][WITHDRAW_CALLBACK]: Processando saque', [
                'transactionId' => $transactionId,
                'status' => $status,
                'value' => $value
            ]);

            // Buscar a solicitação de saque pelo transactionId
            $cashout = SolicitacoesCashOut::where('idTransaction', $transactionId)
                ->orWhere('externalreference', $transactionId)
                ->first();

            if (!$cashout) {
                Log::error('[WOOVI][WITHDRAW_CALLBACK]: Solicitação de saque não encontrada para transactionId: ' . $transactionId);
                return response()->json(['status' => 'error', 'message' => 'Solicitação de saque não encontrada'], 404);
            }

            // Verificar se a solicitação já foi processada
            if ($cashout->status === 'COMPLETED') {
                Log::info('[WOOVI][WITHDRAW_CALLBACK]: Saque já foi processado, verificando webhook', ['transactionId' => $transactionId]);
                
                // Mesmo que já processado, enviar webhook se configurado (importante para modo sandbox)
                if ($cashout->callback && $cashout->callback != 'web') {
                    $payload = [
                        "status" => "paid",
                        "idTransaction" => $cashout->idTransaction,
                        "typeTransaction" => "WITHDRAW",
                        "amount" => $cashout->amount,
                        "netAmount" => $cashout->cash_out_liquido,
                        "fee" => $cashout->taxa_cash_out,
                        "processedAt" => Carbon::now()->toISOString()
                    ];

                    Log::info('[WOOVI][WITHDRAW_CALLBACK]: Enviando webhook para cassino (saque já processado)', [
                        'callback_url' => $cashout->callback,
                        'payload' => $payload
                    ]);

                    try {
                        $response = Http::withHeaders([
                            'Content-Type' => 'application/json',
                            'accept' => 'application/json'
                        ])->timeout(30)->post($cashout->callback, $payload);

                        Log::info('[WOOVI][WITHDRAW_CALLBACK]: Webhook enviado para cassino (saque já processado)', [
                            'callback_url' => $cashout->callback,
                            'status_code' => $response->status(),
                            'response' => $response->body()
                        ]);
                    } catch (\Exception $e) {
                        Log::error('[WOOVI][WITHDRAW_CALLBACK]: Erro ao enviar webhook para cassino (saque já processado)', [
                            'callback_url' => $cashout->callback,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                return response()->json(['status' => 'success', 'message' => 'Saque já processado']);
            }

            // Processar saque baseado no status
            if ($status === 'COMPLETED' || $status === 'paid') {
                // Atualizar status da solicitação
                $cashout->update([
                    'status' => 'COMPLETED',
                    'updated_at' => Carbon::now()
                ]);

                // Buscar o usuário
                $user = User::where('user_id', $cashout->user_id)->first();
                if ($user) {
                    // Decrementar o saldo do usuário (já foi decrementado no momento da solicitação)
                    // Apenas atualizar o valor sacado
                    $user->increment('valor_sacado', $cashout->amount);

                    Log::info('[WOOVI][WITHDRAW_CALLBACK]: Saque processado para usuário', [
                        'user_id' => $user->user_id,
                        'amount' => $cashout->amount
                    ]);
                }

                // ENVIAR WEBHOOK PARA O CASSINO (POSTBACK)
                if ($cashout->callback && $cashout->callback != 'web') {
                    $payload = [
                        "status" => "paid",
                        "idTransaction" => $cashout->idTransaction,
                        "typeTransaction" => "WITHDRAW",
                        "amount" => $cashout->amount,
                        "netAmount" => $cashout->cash_out_liquido,
                        "fee" => $cashout->taxa_cash_out,
                        "processedAt" => Carbon::now()->toISOString()
                    ];

                    Log::info('[WOOVI][WITHDRAW_CALLBACK]: Enviando webhook para cassino', [
                        'callback_url' => $cashout->callback,
                        'payload' => $payload
                    ]);

                    try {
                        $response = Http::withHeaders([
                            'Content-Type' => 'application/json',
                            'accept' => 'application/json'
                        ])->timeout(30)->post($cashout->callback, $payload);

                        Log::info('[WOOVI][WITHDRAW_CALLBACK]: Webhook enviado para cassino', [
                            'callback_url' => $cashout->callback,
                            'status_code' => $response->status(),
                            'response' => $response->body()
                        ]);
                    } catch (\Exception $e) {
                        Log::error('[WOOVI][WITHDRAW_CALLBACK]: Erro ao enviar webhook para cassino', [
                            'callback_url' => $cashout->callback,
                            'error' => $e->getMessage()
                        ]);
                    }
                } else {
                    Log::warning('[WOOVI][WITHDRAW_CALLBACK]: Callback não configurado ou é web', [
                        'callback' => $cashout->callback
                    ]);
                }

                Log::info('[WOOVI][WITHDRAW_CALLBACK]: Saque processado com sucesso', ['transactionId' => $transactionId]);
                return response()->json(['status' => 'success', 'message' => 'Saque processado com sucesso']);

            } else {
                // Saque falhou ou foi cancelado
                $cashout->update([
                    'status' => 'CANCELLED',
                    'updated_at' => Carbon::now()
                ]);

                // Reverter o saldo do usuário se necessário
                $user = User::where('user_id', $cashout->user_id)->first();
                if ($user) {
                    Helper::incrementAmount($user, $cashout->amount, 'saldo');
                    Helper::calculaSaldoLiquido($user->user_id);
                }

                Log::info('[WOOVI][WITHDRAW_CALLBACK]: Saque cancelado/falhou', ['transactionId' => $transactionId]);
                return response()->json(['status' => 'success', 'message' => 'Status atualizado para cancelado']);
            }

        } catch (\Exception $e) {
            Log::error('[WOOVI][WITHDRAW_CALLBACK][ERROR]: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Erro interno do servidor'], 500);
        }
    }
}