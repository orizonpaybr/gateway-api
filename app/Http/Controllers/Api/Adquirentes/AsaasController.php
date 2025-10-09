<?php

namespace App\Http\Controllers\Api\Adquirentes;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\AsaasService;
use App\Services\PushNotificationService;

class AsaasController extends Controller
{
    protected $asaasService;
    private $pushService;

    public function __construct(AsaasService $asaasService, PushNotificationService $pushService)
    {
        $this->asaasService = $asaasService;
        $this->pushService = $pushService;
    }

    /**
     * Callback para depósitos (Cash-in) - Asaas
     */
    public function callbackDeposit(Request $request)
    {
        try {
            $data = $request->all();
            Log::debug('[+] Callback Asaas Deposit: ' . json_encode($data));

            // Valida webhook se token estiver configurado
            $signature = $request->header('asaas-access-token');
            if ($signature && !$this->asaasService->validateWebhook(json_encode($data), $signature)) {
                Log::warning('Callback Asaas Deposit: Webhook inválido');
                return response()->json(['status' => false, 'message' => 'Webhook inválido'], 401);
            }

            // Verifica se é um evento de pagamento
            if (!isset($data['event']) || !isset($data['payment'])) {
                Log::warning('Callback Asaas Deposit: Dados obrigatórios não encontrados');
                return response()->json(['status' => false, 'message' => 'Dados obrigatórios não encontrados']);
            }

            $event = $data['event'];
            $payment = $data['payment'];
            
            // Processa apenas eventos de pagamento aprovado
            if ($event !== 'PAYMENT_CONFIRMED') {
                Log::info("Callback Asaas Deposit: Evento não processado: $event");
                return response()->json(['status' => false, 'message' => 'Evento não processado']);
            }

            $externalReference = $payment['externalReference'] ?? null;
            if (!$externalReference) {
                Log::warning('Callback Asaas Deposit: External reference não encontrado');
                return response()->json(['status' => false, 'message' => 'External reference não encontrado']);
            }

            // Busca a solicitação pelo externalReference
            $cashin = Solicitacoes::where('idTransaction', $externalReference)
                ->where('adquirente_ref', 'asaas')
                ->first();

            if (!$cashin) {
                Log::warning("Callback Asaas Deposit: Solicitação não encontrada para externalReference: $externalReference");
                return response()->json(['status' => false, 'message' => 'Solicitação não encontrada']);
            }

            if ($cashin->status !== 'WAITING_FOR_APPROVAL') {
                Log::warning("Callback Asaas Deposit: Solicitação já processada. Status atual: {$cashin->status}");
                return response()->json(['status' => false, 'message' => 'Solicitação já processada']);
            }

            Log::info("Callback Asaas Deposit: Processando pagamento aprovado para transação: $externalReference");

            // Atualiza status da solicitação
            $updated_at = Carbon::now();
            $cashin->update(['status' => 'PAID_OUT', 'updated_at' => $updated_at]);

            // Busca o usuário
            $user = User::where('user_id', $cashin->user_id)->first();
            
            if ($user) {
                // Incrementa o saldo do usuário
                Helper::incrementAmount($user, $cashin->deposito_liquido, 'saldo');
                Helper::calculaSaldoLiquido($user->user_id);

                Log::info("Callback Asaas Deposit: Saldo incrementado para usuário {$user->user_id} - Valor: {$cashin->deposito_liquido}");

                // Notificação será enviada automaticamente pelo Observer (SolicitacoesObserver)

                // Processa comissão do gerente se existir
                if (isset($user->gerente_id) && !is_null($user->gerente_id)) {
                    $gerente = User::where('user_id', $user->gerente_id)->first();
                    if ($gerente) {
                        $comissao = ($cashin->deposito_liquido * $gerente->comissao_deposito) / 100;
                        if ($comissao > 0) {
                            Helper::incrementAmount($gerente, $comissao, 'saldo');
                            Helper::calculaSaldoLiquido($gerente->user_id);
                            Log::info("Callback Asaas Deposit: Comissão paga para gerente {$gerente->user_id} - Valor: $comissao");
                            
                            // Enviar notificação de comissão ao gerente
                            $this->pushService->sendCommissionNotification(
                                $gerente->user_id,
                                $comissao,
                                "Comissão de depósito"
                            );
                        }
                    }
                }
            } else {
                Log::error("Callback Asaas Deposit: Usuário não encontrado para user_id: {$cashin->user_id}");
            }

            // Atualiza status do pedido se existir
            $order = \App\Models\CheckoutOrders::where('idTransaction', $externalReference)->first();
            if ($order) {
                $order->update(['status' => 'pago']);
                
                // Envia webhook se configurado
                if (!is_null($user->webhook_url) && in_array('pago', (array) $user->webhook_endpoint)) {
                    Http::withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                        ->post($user->webhook_url, [
                            'nome' => $order->name,
                            'cpf' => preg_replace('/\D/', '', $order->cpf),
                            'telefone' => preg_replace('/\D/', '', $order->telefone),
                            'email' => $order->email,
                            'status' => 'pago'
                        ]);
                    Log::info("Callback Asaas Deposit: Webhook enviado para: {$user->webhook_url}");
                }
            }

            // Envia callback personalizado se configurado
            if ($cashin->callback && $cashin->callback !== 'web') {
                $payload = [
                    "status" => "paid",
                    "idTransaction" => $cashin->idTransaction,
                    "typeTransaction" => "PIX"
                ];

                Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'accept' => 'application/json'
                ])->post($cashin->callback, $payload);

                Log::info("Callback Asaas Deposit: Callback personalizado enviado para: {$cashin->callback}");
            }

            return response()->json(['status' => true, 'message' => 'Pagamento processado com sucesso']);

        } catch (\Exception $e) {
            Log::error('Callback Asaas Deposit - Erro: ' . $e->getMessage());
            Log::error('Callback Asaas Deposit - Stack trace: ' . $e->getTraceAsString());
            return response()->json(['status' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Callback para saques (Cash-out) - Asaas
     */
    public function callbackWithdraw(Request $request)
    {
        try {
            $data = $request->all();
            Log::debug('[+] Callback Asaas Withdraw: ' . json_encode($data));

            // Valida webhook se token estiver configurado
            $signature = $request->header('asaas-access-token');
            if ($signature && !$this->asaasService->validateWebhook(json_encode($data), $signature)) {
                Log::warning('Callback Asaas Withdraw: Webhook inválido');
                return response()->json(['status' => false, 'message' => 'Webhook inválido'], 401);
            }

            // Verifica se é um evento de transferência
            if (!isset($data['event']) || !isset($data['transfer'])) {
                Log::warning('Callback Asaas Withdraw: Dados obrigatórios não encontrados');
                return response()->json(['status' => false, 'message' => 'Dados obrigatórios não encontrados']);
            }

            $event = $data['event'];
            $transfer = $data['transfer'];
            
            $externalReference = $transfer['externalReference'] ?? null;
            if (!$externalReference) {
                Log::warning('Callback Asaas Withdraw: External reference não encontrado');
                return response()->json(['status' => false, 'message' => 'External reference não encontrado']);
            }

            // Busca a solicitação pelo externalReference
            $cashout = SolicitacoesCashOut::where('idTransaction', $externalReference)
                ->where('descricao_transacao', '!=', 'WEB')
                ->first();

            if (!$cashout) {
                Log::warning("Callback Asaas Withdraw: Solicitação não encontrada para externalReference: $externalReference");
                return response()->json(['status' => false, 'message' => 'Solicitação não encontrada']);
            }

            if ($cashout->status !== 'PENDING') {
                Log::warning("Callback Asaas Withdraw: Solicitação já processada. Status atual: {$cashout->status}");
                return response()->json(['status' => false, 'message' => 'Solicitação já processada']);
            }

            // Processa eventos de transferência
            if ($event === 'TRANSFER_CREATED' || $event === 'TRANSFER_CONFIRMED') {
                Log::info("Callback Asaas Withdraw: Processando transferência aprovada para transação: $externalReference");

                // Atualiza status da solicitação
                $updated_at = Carbon::now();
                $cashout->update(['status' => 'PAID_OUT', 'updated_at' => $updated_at]);

                Log::info("Callback Asaas Withdraw: Saque processado com sucesso para transação: $externalReference");

                return response()->json(['status' => true, 'message' => 'Saque processado com sucesso']);

            } elseif ($event === 'TRANSFER_CANCELLED' || $event === 'TRANSFER_FAILED') {
                Log::info("Callback Asaas Withdraw: Saque rejeitado para transação: $externalReference - Evento: $event");

                // Atualiza status da solicitação como rejeitada
                $updated_at = Carbon::now();
                $cashout->update(['status' => 'REJECTED', 'updated_at' => $updated_at]);

                // Busca o usuário para reverter o saldo
                $user = User::where('user_id', $cashout->user_id)->first();
                if ($user) {
                    // Reverte o saldo do usuário (usa campo amount se existir)
                    $valorParaReverter = $cashout->amount ?? $cashout->valor ?? 0;
                    Helper::incrementAmount($user, $valorParaReverter, 'saldo');
                    Helper::calculaSaldoLiquido($user->user_id);
                    Log::info("Callback Asaas Withdraw: Saldo revertido para usuário {$user->user_id} - Valor: {$valorParaReverter}");
                }

                return response()->json(['status' => true, 'message' => 'Saque rejeitado processado']);

            } else {
                Log::info("Callback Asaas Withdraw: Evento não processado: $event para transação: $externalReference");
                return response()->json(['status' => false, 'message' => 'Evento não processado']);
            }

        } catch (\Exception $e) {
            Log::error('Callback Asaas Withdraw - Erro: ' . $e->getMessage());
            Log::error('Callback Asaas Withdraw - Stack trace: ' . $e->getTraceAsString());
            return response()->json(['status' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Teste do callback Asaas
     */
    public function testCallback(Request $request)
    {
        $data = $request->all();
        Log::info('[TEST] Callback Asaas Test: ' . json_encode($data));
        
        return response()->json([
            'status' => true,
            'message' => 'Callback Asaas testado com sucesso',
            'received_data' => $data,
            'timestamp' => now()->toDateTimeString()
        ]);
    }
}
