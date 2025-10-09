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

class PixupController extends Controller
{
    private $pushService;

    public function __construct(PushNotificationService $pushService)
    {
        $this->pushService = $pushService;
    }

    /**
     * Callback para depósitos (Cash-in) - Pixup
     */
    public function callbackDeposit(Request $request)
    {
        try {
            $data = $request->all();
            Log::debug('[+] Callback Pixup Deposit: ' . json_encode($data));

            // Verifica se é um evento de pagamento PIX (formato direto ou PIXUP)
            $status = null;
            $transactionId = null;
            $typeTransaction = 'PIX';
            
            // Formato direto Pixup
            if (isset($data['status']) && isset($data['idTransaction'])) {
                $status = $data['status'];
                $transactionId = $data['idTransaction'];
                $typeTransaction = $data['typeTransaction'] ?? 'PIX';
                Log::info("Callback Pixup: Formato direto detectado - Status: $status, TransactionId: $transactionId");
            }
            // Formato PIXUP (Pixup também pode usar)
            elseif (isset($data['requestBody']) && isset($data['requestBody']['external_id'])) {
                $transactionId = $data['requestBody']['external_id'];
                $typeTransaction = $data['requestBody']['transactionType'] ?? 'PIX';
                
                // Mapear status do formato PIXUP para Pixup
                if (isset($data['requestBody']['statusCode']['statusId'])) {
                    $statusId = $data['requestBody']['statusCode']['statusId'];
                    if ($statusId == 1) {
                        $status = 'PAID';
                    } elseif ($statusId == 2) {
                        $status = 'REJECTED';
                    } else {
                        $status = 'PENDING';
                    }
                } elseif (isset($data['requestBody']['status'])) {
                    $status = $data['requestBody']['status'];
                }
                
                Log::info("Callback Pixup: Formato PIXUP detectado - Status: $status, TransactionId: $transactionId");
            }
            
            if (!$status || !$transactionId) {
                Log::warning('Callback Pixup Deposit: Dados obrigatórios não encontrados');
                return response()->json(['status' => false, 'message' => 'Dados obrigatórios não encontrados']);
            }

            // Busca a solicitação pelo idTransaction (primeiro em depósitos, depois em saques)
            $cashin = Solicitacoes::where('idTransaction', $transactionId)
                ->where('adquirente_ref', 'pixup')
                ->first();
            $cashout = null;
            $isWithdraw = false;

            if (!$cashin) {
                // Se não encontrou em depósitos, busca em saques
                $cashout = SolicitacoesCashOut::where('idTransaction', $transactionId)
                    ->where('executor_ordem', 'pixup')
                    ->first();
                if ($cashout) {
                    $isWithdraw = true;
                    Log::info("Callback Pixup: Transação identificada como SAQUE - ID: $transactionId");
                }
            } else {
                Log::info("Callback Pixup: Transação identificada como DEPÓSITO - ID: $transactionId");
            }

            if (!$cashin && !$cashout) {
                Log::warning("Callback Pixup: Solicitação não encontrada para idTransaction: $transactionId");
                
                // Se é um callback de saque (PAYMENT) com status de sucesso, tentar criar a transação
                if ($typeTransaction === 'PAYMENT' && in_array(strtoupper($status), ['PAID', 'APPROVED'])) {
                    $amount = $data['requestBody']['amount'] ?? 0;
                    if ($amount > 0) {
                        Log::info("Callback Pixup: Tentando criar transação de saque ausente", [
                            'transaction_id' => $transactionId,
                            'amount' => $amount,
                            'status' => $status
                        ]);
                        
                        // Criar transação de saque com dados básicos do callback
                        $cashout_data = [
                            "user_id" => "admin", // Usar admin como fallback
                            "externalreference" => $transactionId,
                            "amount" => $amount,
                            "cash_out_liquido" => $amount * 0.895, // Taxa padrão 10.5%
                            "taxa_cash_out" => $amount * 0.105,
                            "pix" => "17865551746", // Chave padrão do admin
                            "pixkey" => "CPF",
                            "beneficiaryname" => "GATEWAY ADMIN",
                            "beneficiarydocument" => "17865551746",
                            "date" => Carbon::now(),
                            "status" => 'PENDING', // Será atualizado para PAID_OUT logo abaixo
                            "idTransaction" => $transactionId,
                            "end_to_end" => $transactionId,
                            "descricao_transacao" => "CALLBACK_RECOVERY",
                            "executor_ordem" => 'pixup',
                            "type" => "PIX",
                            "callback" => env('APP_URL') . '/callback'
                        ];
                        
                        $cashout = SolicitacoesCashOut::create($cashout_data);
                        $isWithdraw = true;
                        
                        Log::info("Callback Pixup: Transação de saque criada via callback recovery", [
                            'cashout_id' => $cashout->id,
                            'transaction_id' => $transactionId
                        ]);
                    } else {
                        Log::warning("Callback Pixup: Não foi possível criar transação - valor inválido", [
                            'amount' => $amount
                        ]);
                        return response()->json(['status' => false, 'message' => 'Solicitação não encontrada e valor inválido']);
                    }
                } else {
                    return response()->json(['status' => false, 'message' => 'Solicitação não encontrada']);
                }
            }

            // Verifica se já foi processada
            if ($isWithdraw) {
                if ($cashout->status !== 'PENDING') {
                    Log::warning("Callback Pixup Withdraw: Solicitação já processada. Status atual: {$cashout->status}");
                    return response()->json(['status' => false, 'message' => 'Solicitação já processada']);
                }
            } else {
                if ($cashin->status !== 'WAITING_FOR_APPROVAL') {
                    Log::warning("Callback Pixup Deposit: Solicitação já processada. Status atual: {$cashin->status}");
                    return response()->json(['status' => false, 'message' => 'Solicitação já processada']);
                }
            }

            // Processa apenas se o status for "paid", "approved" ou "PAID"
            if (in_array(strtoupper($status), ['PAID', 'APPROVED'])) {
                if ($isWithdraw) {
                    Log::info("Callback Pixup Withdraw: Processando saque aprovado para transação: $transactionId");
                    
                    // Atualiza status da solicitação de saque
                    $updated_at = Carbon::now();
                    $cashout->update(['status' => 'PAID_OUT', 'updated_at' => $updated_at]);
                    
                    // CORREÇÃO: Não descontar taxa novamente no callback quando taxa_por_fora = true
                    // A taxa já foi descontada na requisição inicial
                    $user = User::where('user_id', $cashout->user_id)->first();
                    if ($user) {
                        $taxaPorFora = \App\Models\App::first()->taxa_por_fora_api ?? true;
                        
                        if (!$taxaPorFora) {
                            // Apenas descontar se taxa NÃO for por fora
                            $valor_para_descontar = $cashout->amount;
                            
                            Log::info("Callback Pixup Withdraw: Descontando valor do saque (taxa não por fora):", [
                                'user_id' => $user->user_id,
                                'saldo_antes' => $user->saldo,
                                'valor_saque' => $cashout->amount,
                                'valor_para_descontar' => $valor_para_descontar,
                                'taxa_por_fora' => $taxaPorFora
                            ]);
                            
                            \App\Helpers\Helper::decrementAmount($user, $valor_para_descontar, 'saldo');
                        } else {
                            Log::info("Callback Pixup Withdraw: Taxa por fora - não descontando saldo novamente:", [
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
                                'adquirente' => 'PIXUP',
                                'valor_bruto' => $cashout->amount,
                                'valor_descontado' => $valor_descontado,
                                'taxa_cash_out' => $cashout->taxa_cash_out,
                                'taxa_por_fora' => $taxaPorFora,
                                'external_id' => $transactionId,
                                'operacao' => 'callbackPixupWithdraw'
                            ]
                        );
                        
                        Log::info("Callback Pixup Withdraw: Saldo atualizado:", [
                            'user_id' => $user->user_id,
                            'saldo_depois' => $user->fresh()->saldo,
                            'valor_sacado' => $user->fresh()->valor_sacado
                        ]);
                    }
                    
                    // Envia callback para o baasPostbackUrl se configurado
                    if ($cashout->callback && $cashout->callback !== 'web' && $cashout->callback !== env('APP_URL') . '/callback') {
                        $this->enviarCallbackSaque($cashout, $transactionId, $typeTransaction);
                    }
                    
                    Log::info("Callback Pixup Withdraw: Saque processado com sucesso para transação: $transactionId");
                    
                    return response()->json(['status' => true, 'message' => 'Saque processado com sucesso']);
                } else {
                    Log::info("Callback Pixup Deposit: Processando depósito aprovado para transação: $transactionId");

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
                                'adquirente' => 'PIXUP',
                                'cashin_id' => $cashin->id,
                                'transaction_id' => $transactionId,
                                'valor_bruto' => $cashin->amount,
                                'valor_liquido' => $cashin->deposito_liquido,
                                'operacao' => 'pixup_callback_deposit'
                            ]
                        );

                        Log::info("Callback Pixup Deposit: Saldo incrementado para usuário {$user->user_id} - Valor: {$cashin->deposito_liquido}");

                        // Notificação será enviada automaticamente pelo Observer (SolicitacoesObserver)

                        // Processa splits se configurados
                        if ($cashin->split_email && $cashin->split_percentage) {
                            $splitResults = \App\Traits\SplitTrait::processSplits($cashin, $user);
                            Log::info("Callback Pixup Deposit: Splits processados", [
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
                                    Log::info("Callback Pixup Deposit: Comissão paga para gerente {$gerente->user_id} - Valor: $comissao");
                                    
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
                        Log::error("Callback Pixup Deposit: Usuário não encontrado para user_id: {$cashin->user_id}");
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
                            Log::info("Callback Pixup Deposit: Webhook enviado para: {$user->webhook_url}");
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

                        Log::info("Callback Pixup Deposit: Callback personalizado enviado para: {$cashin->callback}");
                    }

                    return response()->json(['status' => true, 'message' => 'Pagamento processado com sucesso']);
                }

            } else {
                Log::info("Callback Pixup Deposit: Status não processado: $status para transação: $transactionId");
                return response()->json(['status' => false, 'message' => 'Status não processado']);
            }

        } catch (\Exception $e) {
            Log::error('Callback Pixup Deposit - Erro: ' . $e->getMessage());
            Log::error('Callback Pixup Deposit - Stack trace: ' . $e->getTraceAsString());
            return response()->json(['status' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Callback para saques (Cash-out) - Pixup
     */
    public function callbackWithdraw(Request $request)
    {
        try {
            $data = $request->all();
            Log::debug('[+] Callback Pixup Withdraw: ' . json_encode($data));

            // Verifica se é um evento de transferência PIX
            if (!isset($data['status']) || !isset($data['idTransaction'])) {
                Log::warning('Callback Pixup Withdraw: Dados obrigatórios não encontrados');
                return response()->json(['status' => false, 'message' => 'Dados obrigatórios não encontrados']);
            }

            $status = $data['status'];
            $transactionId = $data['idTransaction'];
            $typeTransaction = $data['typeTransaction'] ?? 'PIX';

            // Busca a solicitação pelo idTransaction
            $cashout = SolicitacoesCashOut::where('idTransaction', $transactionId)
                ->where('descricao_transacao', '!=', 'WEB')
                ->first();

            if (!$cashout) {
                Log::warning("Callback Pixup Withdraw: Solicitação não encontrada para idTransaction: $transactionId");
                return response()->json(['status' => false, 'message' => 'Solicitação não encontrada']);
            }

            if ($cashout->status !== 'PENDING') {
                Log::warning("Callback Pixup Withdraw: Solicitação já processada. Status atual: {$cashout->status}");
                return response()->json(['status' => false, 'message' => 'Solicitação já processada']);
            }

            // Processa apenas se o status for "paid", "approved" ou "completed"
            if (in_array($status, ['paid', 'approved', 'completed'])) {
                Log::info("Callback Pixup Withdraw: Processando saque aprovado para transação: $transactionId");

                // Atualiza status da solicitação
                $updated_at = Carbon::now();
                $cashout->update(['status' => 'PAID_OUT', 'updated_at' => $updated_at]);

                Log::info("Callback Pixup Withdraw: Saque processado com sucesso para transação: $transactionId");

                return response()->json(['status' => true, 'message' => 'Saque processado com sucesso']);

            } elseif (in_array($status, ['failed', 'rejected', 'cancelled'])) {
                Log::info("Callback Pixup Withdraw: Saque rejeitado para transação: $transactionId - Status: $status");

                // Atualiza status da solicitação como rejeitada
                $updated_at = Carbon::now();
                $cashout->update(['status' => 'REJECTED', 'updated_at' => $updated_at]);

                // Busca o usuário para reverter o saldo
                $user = User::where('user_id', $cashout->user_id)->first();
                if ($user) {
                    // Reverte o saldo do usuário
                    $valorParaReverter = $cashout->amount ?? $cashout->valor ?? 0;
                    Helper::incrementAmount($user, $valorParaReverter, 'saldo');
                    Helper::calculaSaldoLiquido($user->user_id);
                    
                    // Log específico para reversão de saque
                    \App\Helpers\BalanceLogHelper::logSaqueOperation(
                        'SAQUE_REVERT',
                        $user,
                        $valorParaReverter,
                        [
                            'adquirente' => 'PIXUP',
                            'cashout_id' => $cashout->id,
                            'external_id' => $cashout->external_id,
                            'status_anterior' => 'PENDING',
                            'status_novo' => 'CANCELLED',
                            'operacao' => 'pixup_callback_withdraw'
                        ]
                    );
                    
                    Log::info("Callback Pixup Withdraw: Saldo revertido para usuário {$user->user_id} - Valor: {$valorParaReverter}");
                }

                return response()->json(['status' => true, 'message' => 'Saque rejeitado processado']);

            } else {
                Log::info("Callback Pixup Withdraw: Status não processado: $status para transação: $transactionId");
                return response()->json(['status' => false, 'message' => 'Status não processado']);
            }

        } catch (\Exception $e) {
            Log::error('Callback Pixup Withdraw - Erro: ' . $e->getMessage());
            Log::error('Callback Pixup Withdraw - Stack trace: ' . $e->getTraceAsString());
            return response()->json(['status' => false, 'message' => 'Erro interno do servidor'], 500);
        }
    }

    /**
     * Teste do callback Pixup
     */
    public function testCallback(Request $request)
    {
        $data = $request->all();
        Log::info('[TEST] Callback Pixup Test: ' . json_encode($data));
        
        return response()->json([
            'status' => true,
            'message' => 'Callback Pixup testado com sucesso',
            'received_data' => $data,
            'timestamp' => now()->toDateTimeString()
        ]);
    }

    /**
     * Envia callback para o baasPostbackUrl do cassino
     */
    private function enviarCallbackSaque($cashout, $transactionId, $typeTransaction)
    {
        try {
            $callbackUrl = $cashout->callback;
            
            Log::info("Callback Pixup: Enviando callback para cassino", [
                'callback_url' => $callbackUrl,
                'transaction_id' => $transactionId,
                'user_id' => $cashout->user_id
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

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->timeout(30)->post($callbackUrl, $payload);

            Log::info("Callback Pixup: Resposta do cassino", [
                'status_code' => $response->status(),
                'response_body' => $response->body(),
                'callback_url' => $callbackUrl
            ]);

            if ($response->successful()) {
                Log::info("Callback Pixup: Callback enviado com sucesso para o cassino");
            } else {
                Log::warning("Callback Pixup: Erro ao enviar callback para o cassino", [
                    'status_code' => $response->status(),
                    'response_body' => $response->body()
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Callback Pixup: Erro ao enviar callback para o cassino", [
                'error' => $e->getMessage(),
                'callback_url' => $callbackUrl ?? 'não definido',
                'transaction_id' => $transactionId
            ]);
        }
    }
}