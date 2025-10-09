<?php

namespace App\Http\Controllers\Api\Adquirentes;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\User;
use App\Services\PushNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class XDPagController extends Controller
{
    private $pushService;

    public function __construct(PushNotificationService $pushService)
    {
        $this->pushService = $pushService;
    }
    /**
     * Callback para depósitos (Cash-in) - XDPag
     */
    public function callbackDeposit(Request $request)
    {
        try {
            $data = $request->all();
            Log::debug('[+] Callback XDPag Deposit: ' . json_encode($data));

            // Verifica se é um evento de pagamento PIX
            $status = null;
            $transactionId = null;
            $typeTransaction = 'PIX';
            
            // Formato XDPag - Versão 1 (com type e data)
            if (isset($data['type']) && $data['type'] === 'PAYMENT') {
                $status = $data['data']['status'] ?? null;
                $transactionId = $data['data']['id'] ?? null;
                $typeTransaction = 'PIX';
                Log::info("Callback XDPag: Formato v1 detectado - Status: $status, TransactionId: $transactionId");
            }
            // Formato XDPag - Versão 2 (formato direto)
            else if (isset($data['id']) && isset($data['status'])) {
                $status = $data['status'];
                $transactionId = $data['id'];
                $typeTransaction = 'PIX';
                Log::info("Callback XDPag: Formato v2 detectado - Status: $status, TransactionId: $transactionId");
            }
            // Formato XDPag - Versão 3 (com type PAYIN e data)
            else if (isset($data['type']) && $data['type'] === 'PAYIN') {
                $status = $data['data']['status'] ?? null;
                $transactionId = $data['data']['externalId'] ?? null; // Usar externalId em vez de id
                $typeTransaction = 'PIX';
                Log::info("Callback XDPag: Formato v3 detectado - Status: $status, TransactionId (externalId): $transactionId");
            }
            
            if (!$status || !$transactionId) {
                Log::warning('Callback XDPag Deposit: Dados obrigatórios não encontrados');
                return response()->json(['status' => false, 'message' => 'Dados obrigatórios não encontrados']);
            }

            // Busca a solicitação pelo idTransaction (primeiro em depósitos, depois em saques)
            $cashin = Solicitacoes::where('idTransaction', $transactionId)
                ->where('adquirente_ref', 'xdpag')
                ->first();
            $cashout = null;
            $isWithdraw = false;

            if (!$cashin) {
                // Se não encontrou em depósitos, busca em saques
                $cashout = SolicitacoesCashOut::where('idTransaction', $transactionId)
                    ->where('executor_ordem', 'xdpag')
                    ->first();
                if ($cashout) {
                    $isWithdraw = true;
                    Log::info("Callback XDPag: Transação identificada como SAQUE - ID: $transactionId");
                }
            } else {
                Log::info("Callback XDPag: Transação identificada como DEPÓSITO - ID: $transactionId");
            }

            if (!$cashin && !$cashout) {
                Log::warning("Callback XDPag: Solicitação não encontrada para idTransaction: $transactionId");
                return response()->json(['status' => false, 'message' => 'Solicitação não encontrada']);
            }

            // Verifica se já foi processada
            if ($isWithdraw) {
                if ($cashout->status !== 'PENDING') {
                    Log::warning("Callback XDPag Withdraw: Solicitação já processada. Status atual: {$cashout->status}");
                    return response()->json(['status' => false, 'message' => 'Solicitação já processada']);
                }
            } else {
                if ($cashin->status !== 'WAITING_FOR_APPROVAL') {
                    Log::warning("Callback XDPag Deposit: Solicitação já processada. Status atual: {$cashin->status}");
                    return response()->json(['status' => false, 'message' => 'Solicitação já processada']);
                }
            }

            // Processa apenas se o status for "FINISHED" ou "PAID"
            if (in_array(strtoupper($status), ['FINISHED', 'PAID'])) {
                if ($isWithdraw) {
                    Log::info("Callback XDPag Withdraw: Processando saque aprovado para transação: $transactionId");
                    
                    // PROTEÇÃO: Verifica se saque ainda não foi processado para evitar duplicação
                    if ($cashout->status === 'PENDING') {
                        // Atualiza status da solicitação de saque
                        $updated_at = Carbon::now();
                        $cashout->update(['status' => 'PAID_OUT', 'updated_at' => $updated_at]);
                        
                        Log::info("Callback XDPag Withdraw: Status atualizado para PAID_OUT para transação: $transactionId");
                        
                        // CORREÇÃO: Não descontar taxa novamente no callback quando taxa_por_fora = true
                        // A taxa já foi descontada na requisição inicial
                        $user = User::where('user_id', $cashout->user_id)->first();
                        if ($user) {
                            $taxaPorFora = \App\Models\App::first()->taxa_por_fora_api ?? true;
                            
                            if (!$taxaPorFora) {
                                // Apenas descontar se taxa NÃO for por fora
                                $valor_para_descontar = $cashout->amount;
                                
                                Log::info("Callback XDPag Withdraw: Descontando valor do saque (taxa não por fora):", [
                                    'user_id' => $user->user_id,
                                    'saldo_antes' => $user->saldo,
                                    'valor_saque' => $cashout->amount,
                                    'valor_para_descontar' => $valor_para_descontar,
                                    'taxa_por_fora' => $taxaPorFora
                                ]);
                                
                                \App\Helpers\Helper::decrementAmount($user, $valor_para_descontar, 'saldo');
                            } else {
                                Log::info("Callback XDPag Withdraw: Taxa por fora - não descontando saldo novamente:", [
                                    'user_id' => $user->user_id,
                                    'saldo_atual' => $user->saldo,
                                    'valor_saque' => $cashout->amount,
                                    'taxa_cash_out' => $cashout->taxa_cash_out,
                                    'taxa_por_fora' => $taxaPorFora
                                ]);
                            }
                            
                            $user->increment('valor_sacado', $cashout->amount);
                            
                            // Log específico para saque
                            $valor_descontado = $taxaPorFora ? ($cashout->amount + $cashout->taxa_cash_out) : ($valor_para_descontar ?? $cashout->amount);
                            \App\Helpers\BalanceLogHelper::logSaqueOperation(
                                'SAQUE_CALLBACK',
                                $user,
                                $cashout->amount,
                                [
                                    'adquirente' => 'XDPAG',
                                    'valor_bruto' => $cashout->amount,
                                    'valor_descontado' => $valor_descontado,
                                    'taxa_cash_out' => $cashout->taxa_cash_out,
                                    'taxa_por_fora' => $taxaPorFora,
                                    'external_id' => $transactionId,
                                    'operacao' => 'callbackXDPagWithdraw'
                                ]
                            );
                            
                            Log::info("Callback XDPag Withdraw: Saldo atualizado:", [
                                'user_id' => $user->user_id,
                                'saldo_depois' => $user->fresh()->saldo,
                                'valor_sacado' => $user->fresh()->valor_sacado
                            ]);
                        }
                    } else {
                        Log::info("Callback XDPag Withdraw: Saque já processado anteriormente", [
                            'transaction_id' => $transactionId,
                            'current_status' => $cashout->status
                        ]);
                    }
                    
                    // Envia callback para o baasPostbackUrl se configurado
                    if ($cashout->callback && $cashout->callback !== 'web' && $cashout->callback !== env('APP_URL') . '/callback') {
                        $this->enviarCallbackSaque($cashout, $transactionId, $typeTransaction);
                    }
                    
                    Log::info("Callback XDPag Withdraw: Saque processado com sucesso para transação: $transactionId");
                    
                    // Enviar notificação de saque
                    $this->pushService->sendWithdrawNotification(
                        $cashout->user_id, 
                        $cashout->cash_out_liquido, 
                        $transactionId
                    );
                    
                    return response()->json(['status' => true, 'message' => 'Saque processado com sucesso']);
                } else {
                    Log::info("Callback XDPag Deposit: Processando depósito aprovado para transação: $transactionId");

                    // Atualiza status da solicitação de depósito
                    $updated_at = Carbon::now();
                    $cashin->update(['status' => 'PAID_OUT', 'updated_at' => $updated_at]);

                    // Busca o usuário
                    $user = User::where('user_id', $cashin->user_id)->first();
                    
                    if ($user) {
                        // Incrementa o saldo do usuário
                        Helper::incrementAmount($user, $cashin->deposito_liquido, 'saldo');
                        Helper::calculaSaldoLiquido($user->user_id);

                        // Log específico para depósito
                        \App\Helpers\BalanceLogHelper::logDepositOperation(
                            'DEPOSIT_CREDIT',
                            $user,
                            $cashin->deposito_liquido,
                            [
                                'adquirente' => 'XDPAG',
                                'cashin_id' => $cashin->id,
                                'transaction_id' => $transactionId,
                                'valor_bruto' => $cashin->amount,
                                'valor_liquido' => $cashin->deposito_liquido,
                                'operacao' => 'xdpag_callback_deposit'
                            ]
                        );

                        Log::info("Callback XDPag Deposit: Saldo incrementado para usuário {$user->user_id} - Valor: {$cashin->deposito_liquido}");

                        // Notificação será enviada automaticamente pelo Observer (SolicitacoesObserver)

                        // Processa splits se configurados
                        if ($cashin->split_email && $cashin->split_percentage) {
                            $splitResults = \App\Traits\SplitTrait::processSplits($cashin, $user);
                            Log::info("Callback XDPag Deposit: Splits processados", [
                                'solicitacao_id' => $cashin->id,
                                'split_results' => $splitResults
                            ]);
                        }

                        // Processa comissão do gerente se existir
                        if (isset($user->gerente_id) && !is_null($user->gerente_id)) {
                            $gerente = User::where('user_id', $user->gerente_id)->first();
                            if ($gerente) {
                                $comissao = ($cashin->deposito_liquido * $gerente->comissao_deposito) / 100;
                                if ($comissao > 0) {
                                    Helper::incrementAmount($gerente, $comissao, 'saldo');
                                    Helper::calculaSaldoLiquido($gerente->user_id);
                                    Log::info("Callback XDPag Deposit: Comissão paga para gerente {$gerente->user_id} - Valor: $comissao");
                                    
                                    // Enviar notificação de comissão
                                    $this->pushService->sendCommissionNotification(
                                        $gerente->user_id, 
                                        $comissao, 
                                        "Comissão de depósito - {$user->user_id}"
                                    );
                                }
                            }
                        }
                    } else {
                        Log::error("Callback XDPag Deposit: Usuário não encontrado para user_id: {$cashin->user_id}");
                    }

                    // Atualiza status do pedido se existir
                    $order = \App\Models\CheckoutOrders::where('idTransaction', $transactionId)->first();
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
                            Log::info("Callback XDPag Deposit: Webhook enviado para: {$user->webhook_url}");
                        }
                    }

                    // Envia callback personalizado se configurado
                    if ($cashin->callback && $cashin->callback !== 'web') {
                        $payload = [
                            "status" => "paid",
                            "idTransaction" => $cashin->idTransaction,
                            "typeTransaction" => $typeTransaction,
                            "amount" => $cashin->amount,
                            "debtor_name" => $cashin->client_name,
                            "email" => $cashin->client_email,
                            "debtor_document_number" => $cashin->client_document,
                            "phone" => $cashin->client_telefone,
                            "created_at" => $cashin->created_at,
                            "paid_at" => $cashin->updated_at,
                            "split_processed" => $cashin->split_email && $cashin->split_percentage ? true : false,
                            "split_amount" => $cashin->split_email && $cashin->split_percentage ? (float) (($cashin->amount * $cashin->split_percentage) / 100) : 0,
                            "split_recipient" => $cashin->split_email ?? null
                        ];

                        Http::withHeaders([
                            'Content-Type' => 'application/json',
                            'accept' => 'application/json'
                        ])->post($cashin->callback, $payload);

                        Log::info("Callback XDPag Deposit: Callback personalizado enviado para: {$cashin->callback}");
                    }

                    return response()->json(['status' => true, 'message' => 'Pagamento processado com sucesso']);
                }

            } else {
                Log::info("Callback XDPag Deposit: Status não processado: $status para transação: $transactionId");
                return response()->json(['status' => false, 'message' => 'Status não processado']);
            }

        } catch (\Exception $e) {
            Log::error('Callback XDPag Deposit - Erro: ' . $e->getMessage());
            Log::error('Callback XDPag Deposit - Stack trace: ' . $e->getTraceAsString());
            return response()->json(['status' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Callback para saques (Cash-out) - XDPag
     */
    public function callbackWithdraw(Request $request)
    {
        try {
            $data = $request->all();
            Log::debug('[+] Callback XDPag Withdraw: ' . json_encode($data));

            // Verifica se é um evento de transferência PIX
            if (!isset($data['type']) || $data['type'] !== 'PAYOUT') {
                Log::warning('Callback XDPag Withdraw: Tipo de evento não é PAYOUT');
                return response()->json(['status' => false, 'message' => 'Tipo de evento inválido']);
            }

            $status = $data['data']['status'] ?? null;
            $transactionId = $data['data']['externalId'] ?? $data['data']['id'] ?? null; // Usar externalId primeiro, depois id
            $typeTransaction = 'PIX';
            
            Log::info("Callback XDPag Withdraw: Status: $status, TransactionId: $transactionId (externalId: " . ($data['data']['externalId'] ?? 'não fornecido') . ", id: " . ($data['data']['id'] ?? 'não fornecido') . ")");

            if (!$status || !$transactionId) {
                Log::warning('Callback XDPag Withdraw: Dados obrigatórios não encontrados');
                return response()->json(['status' => false, 'message' => 'Dados obrigatórios não encontrados']);
            }

            // Busca a solicitação pelo idTransaction
            $cashout = SolicitacoesCashOut::where('idTransaction', $transactionId)
                ->where('descricao_transacao', '!=', 'WEB')
                ->first();

            if (!$cashout) {
                Log::warning("Callback XDPag Withdraw: Solicitação não encontrada para idTransaction: $transactionId");
                return response()->json(['status' => false, 'message' => 'Solicitação não encontrada']);
            }

            if ($cashout->status !== 'PENDING') {
                Log::warning("Callback XDPag Withdraw: Solicitação já processada. Status atual: {$cashout->status}");
                return response()->json(['status' => false, 'message' => 'Solicitação já processada']);
            }

            // Processa apenas se o status for "FINISHED"
            if (in_array($status, ['FINISHED'])) {
                Log::info("Callback XDPag Withdraw: Processando saque aprovado para transação: $transactionId");

                // Atualiza status da solicitação
                $updated_at = Carbon::now();
                $cashout->update(['status' => 'PAID_OUT', 'updated_at' => $updated_at]);

                Log::info("Callback XDPag Withdraw: Saque processado com sucesso para transação: $transactionId");

                // Enviar notificação de saque
                $this->pushService->sendWithdrawNotification(
                    $cashout->user_id, 
                    $cashout->cash_out_liquido, 
                    $transactionId
                );

                // Envia callback personalizado se configurado
                if ($cashout->callback && $cashout->callback !== 'web') {
                    $payload = [
                        "status" => "paid",
                        "idTransaction" => $cashout->idTransaction,
                        "typeTransaction" => "WITHDRAW",
                        "amount" => $cashout->amount,
                        "netAmount" => $cashout->cash_out_liquido,
                        "fee" => $cashout->taxa_cash_out,
                        "externalId" => $cashout->externalreference,
                        "processedAt" => Carbon::now()->toISOString()
                    ];

                    try {
                        Http::withHeaders([
                            'Content-Type' => 'application/json',
                            'accept' => 'application/json'
                        ])->timeout(30)->post($cashout->callback, $payload);

                        Log::info("Callback XDPag Withdraw: Callback personalizado enviado para: {$cashout->callback}");
                    } catch (\Exception $e) {
                        Log::error("Callback XDPag Withdraw: Erro ao enviar callback personalizado", [
                            'callback_url' => $cashout->callback,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                return response()->json(['status' => true, 'message' => 'Saque processado com sucesso']);

            } elseif (in_array($status, ['REVERSED', 'PARTIALLY_REVERSED'])) {
                Log::info("Callback XDPag Withdraw: Saque revertido para transação: $transactionId - Status: $status");

                // PROTEÇÃO CRÍTICA: NUNCA reverter saldo se o saque já foi processado
                // Isso evita reversões indevidas quando XDPag envia callbacks duplicados
                if ($cashout->status !== 'PAID_OUT' && $cashout->status !== 'COMPLETED') {
                    // Atualiza status da solicitação como rejeitada
                    $updated_at = Carbon::now();
                    $cashout->update(['status' => 'REJECTED', 'updated_at' => $updated_at]);

                    // Busca o usuário para reverter o saldo
                    $user = User::where('user_id', $cashout->user_id)->first();
                    if ($user) {
                        // Reverte o saldo do usuário
                        Helper::incrementAmount($user, $cashout->amount, 'saldo');
                        Helper::calculaSaldoLiquido($user->user_id);
                        
                        // Log específico para reversão de saque
                        \App\Helpers\BalanceLogHelper::logSaqueOperation(
                            'SAQUE_REVERT',
                            $user,
                            $cashout->amount,
                            [
                                'adquirente' => 'XDPAG',
                                'cashout_id' => $cashout->id,
                                'external_id' => $cashout->external_id,
                                'status_anterior' => 'PENDING',
                                'status_novo' => 'CANCELLED',
                                'operacao' => 'xdpag_callback_withdraw'
                            ]
                        );
                        
                        Log::info("Callback XDPag Withdraw: Saldo revertido para usuário {$user->user_id} - Valor: {$cashout->amount}");
                    }
                } else {
                    Log::warning("Callback XDPag Withdraw: Tentativa de rejeitar saque já processado IGNORADA", [
                        'transaction_id' => $transactionId,
                        'current_status' => $cashout->status,
                        'attempted_status' => $status,
                        'reason' => 'Saque já foi processado com sucesso - não é possível reverter'
                    ]);
                }

                return response()->json(['status' => true, 'message' => 'Saque revertido processado']);

            } else {
                Log::info("Callback XDPag Withdraw: Status não processado: $status para transação: $transactionId");
                return response()->json(['status' => false, 'message' => 'Status não processado']);
            }

        } catch (\Exception $e) {
            Log::error('Callback XDPag Withdraw - Erro: ' . $e->getMessage());
            Log::error('Callback XDPag Withdraw - Stack trace: ' . $e->getTraceAsString());
            return response()->json(['status' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Teste do callback XDPag
     */
    public function testCallback(Request $request)
    {
        $data = $request->all();
        Log::info('[TEST] Callback XDPag Test: ' . json_encode($data));
        
        return response()->json([
            'status' => true,
            'message' => 'Callback XDPag testado com sucesso',
            'received_data' => $data,
            'timestamp' => now()->toDateTimeString()
        ]);
    }

    /**
     * Envia callback para o baasPostbackUrl do cassino
     */
    private function enviarCallbackSaque($cashout, $transactionId, $typeTransaction)
    {
        $callbackUrl = $cashout->callback;
        $maxTentativas = 3;
        $tentativa = 1;
        
        Log::info("Callback XDPag: Iniciando envio de callback para cassino", [
            'callback_url' => $callbackUrl,
            'transaction_id' => $transactionId,
            'user_id' => $cashout->user_id,
            'max_tentativas' => $maxTentativas
        ]);

        // Payload do callback para o cassino
        $payload = [
            'status' => 'paid',
            'idTransaction' => $transactionId,
            'typeTransaction' => $typeTransaction,
            'amount' => $cashout->amount,
            'cash_out_liquido' => $cashout->cash_out_liquido,
            'pix_key' => $cashout->pix,
            'pix_key_type' => $cashout->pixkey,
            'beneficiary_name' => $cashout->beneficiaryname,
            'beneficiary_document' => $cashout->beneficiarydocument,
            'date' => $cashout->date,
            'externalId' => $cashout->externalreference,
            'timestamp' => now()->toISOString()
        ];

        while ($tentativa <= $maxTentativas) {
            try {
                Log::info("Callback XDPag: Tentativa {$tentativa}/{$maxTentativas}", [
                    'callback_url' => $callbackUrl,
                    'transaction_id' => $transactionId
                ]);

                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ])->timeout(30)->post($callbackUrl, $payload);

                Log::info("Callback XDPag: Resposta do cassino (tentativa {$tentativa})", [
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'callback_url' => $callbackUrl
                ]);

                if ($response->successful()) {
                    Log::info("Callback XDPag: Callback enviado com sucesso para o cassino", [
                        'tentativa' => $tentativa,
                        'transaction_id' => $transactionId
                    ]);
                    return; // Sucesso, sair do loop
                } else {
                    Log::warning("Callback XDPag: Erro ao enviar callback para o cassino (tentativa {$tentativa})", [
                        'status_code' => $response->status(),
                        'response_body' => $response->body(),
                        'tentativa' => $tentativa,
                        'max_tentativas' => $maxTentativas
                    ]);
                }

            } catch (\Exception $e) {
                Log::error("Callback XDPag: Exceção ao enviar callback para o cassino (tentativa {$tentativa})", [
                    'error' => $e->getMessage(),
                    'callback_url' => $callbackUrl,
                    'transaction_id' => $transactionId,
                    'tentativa' => $tentativa,
                    'max_tentativas' => $maxTentativas
                ]);
            }

            $tentativa++;
            
            // Aguardar antes da próxima tentativa (exceto na última)
            if ($tentativa <= $maxTentativas) {
                $delay = $tentativa * 2; // 2s, 4s, 6s...
                Log::info("Callback XDPag: Aguardando {$delay}s antes da próxima tentativa");
                sleep($delay);
            }
        }

        // Se chegou aqui, todas as tentativas falharam
        Log::error("Callback XDPag: Todas as tentativas falharam após {$maxTentativas} tentativas", [
            'callback_url' => $callbackUrl,
            'transaction_id' => $transactionId,
            'max_tentativas' => $maxTentativas
        ]);
        
        // IMPORTANTE: Mesmo com falha em todas as tentativas, NÃO revertemos o saldo
        // pois o XDPag já processou o saque com sucesso
        Log::info("Callback XDPag: Saque mantido como processado mesmo com falha em todas as tentativas de callback", [
            'transaction_id' => $transactionId,
            'reason' => 'XDPag já processou o saque com sucesso - falha no callback não afeta o pagamento'
        ]);
    }
}

