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

class PrimePay7Controller extends Controller
{
    private $pushService;

    public function __construct(PushNotificationService $pushService)
    {
        $this->pushService = $pushService;
    }

    /**
     * Callback para depósitos (Cash-in) - PrimePay7
     */
    public function callbackDeposit(Request $request)
    {
        try {
            $data = $request->all();
            Log::info('=== WEBHOOK PRIMEPAY7 RECEBIDO ===');
            Log::info('Timestamp: ' . now()->format('Y-m-d H:i:s'));
            Log::info('IP: ' . $request->ip());
            Log::info('User-Agent: ' . $request->userAgent());
            Log::info('Headers: ' . json_encode($request->headers->all()));
            Log::info('Dados recebidos: ' . json_encode($data, JSON_PRETTY_PRINT));
            Log::info('=====================================');

            // Verifica se é um evento de pagamento PIX
            $status = null;
            $transactionId = null;
            $typeTransaction = 'PIX';
            
            // Formato PrimePay7 - verificar primeiro se tem data.status
            if (isset($data['data']['status']) && isset($data['data']['id'])) {
                $status = $data['data']['status'];
                $transactionId = $data['data']['id'];
                $typeTransaction = $data['data']['paymentMethod'] ?? 'PIX';
                Log::info("🔍 FORMATO PRIMEPAY7 DETECTADO (data.status):");
                Log::info("   Status: $status");
                Log::info("   Transaction ID: $transactionId");
                Log::info("   Tipo: $typeTransaction");
                Log::info("   Valor: R$ " . number_format($data['data']['amount'] / 100, 2, ',', '.'));
            }
            // Formato PrimePay7 - nível raiz
            elseif (isset($data['status']) && (isset($data['transaction_id']) || isset($data['externalId']))) {
                $status = $data['status'];
                $transactionId = $data['transaction_id'] ?? $data['externalId'];
                $typeTransaction = $data['type'] ?? 'PIX';
                Log::info("🔍 FORMATO PRIMEPAY7 DETECTADO (raiz):");
                Log::info("   Status: $status");
                Log::info("   Transaction ID: $transactionId");
                Log::info("   Tipo: $typeTransaction");
            }
            // Formato alternativo
            elseif (isset($data['event']) && isset($data['data'])) {
                $eventData = $data['data'];
                $status = $eventData['status'] ?? null;
                $transactionId = $eventData['id'] ?? null;
                $typeTransaction = $eventData['type'] ?? 'PIX';
                Log::info("🔍 FORMATO ALTERNATIVO DETECTADO:");
                Log::info("   Event: " . ($data['event'] ?? 'N/A'));
                Log::info("   Status: $status");
                Log::info("   Transaction ID: $transactionId");
                Log::info("   Tipo: $typeTransaction");
            }

            if (!$transactionId || !$status) {
                Log::error('❌ DADOS INSUFICIENTES NO WEBHOOK:');
                Log::error('   Transaction ID: ' . ($transactionId ?? 'NÃO ENCONTRADO'));
                Log::error('   Status: ' . ($status ?? 'NÃO ENCONTRADO'));
                Log::error('   Dados completos: ' . json_encode($data, JSON_PRETTY_PRINT));
                return response()->json(['status' => false, 'message' => 'Dados insuficientes']);
            }

            // Processar apenas pagamentos aprovados
            if ($status === 'PAID' || $status === 'paid' || $status === 'APPROVED' || $status === 'COMPLETED') {
                Log::info("✅ STATUS DE PAGAMENTO APROVADO DETECTADO: $status");
                Log::info("🔍 Buscando solicitação com Transaction ID: $transactionId");
                
                // Buscar pela transação usando o ID da PrimePay7
                $cashin = Solicitacoes::where('primepay7_id', $transactionId)->first();
                
                if (!$cashin) {
                    Log::error("❌ SOLICITAÇÃO NÃO ENCONTRADA:");
                    Log::error("   Transaction ID: $transactionId");
                    Log::error("   Verificando se existe na tabela Solicitacoes...");
                    return response()->json(['status' => false, 'message' => 'Solicitação não encontrada']);
                }
                
                Log::info("✅ SOLICITAÇÃO ENCONTRADA:");
                Log::info("   ID: " . $cashin->id);
                Log::info("   User ID: " . $cashin->user_id);
                Log::info("   Valor: R$ " . number_format($cashin->amount, 2, ',', '.'));
                Log::info("   Valor Líquido: R$ " . number_format($cashin->deposito_liquido, 2, ',', '.'));
                Log::info("   Status Atual: " . $cashin->status);
                Log::info("   Callback URL: " . ($cashin->callback ?? 'NÃO CONFIGURADO'));

                if ($cashin->status !== "WAITING_FOR_APPROVAL") {
                    Log::warning("⚠️ SOLICITAÇÃO JÁ PROCESSADA:");
                    Log::warning("   Status atual: {$cashin->status}");
                    Log::warning("   Não será processada novamente");
                    return response()->json(['status' => true, 'message' => 'Solicitação já processada']);
                }

                Log::info("🔄 PROCESSANDO PAGAMENTO:");
                Log::info("   Atualizando status de WAITING_FOR_APPROVAL para PAID_OUT");
                
                $updated_at = Carbon::now();
                $cashin->update(['status' => 'PAID_OUT', 'updated_at' => $updated_at]);
                
                Log::info("✅ STATUS ATUALIZADO COM SUCESSO");

                $user = User::where('user_id', $cashin->user_id)->first();
                if ($user) {
                    Log::info("👤 USUÁRIO ENCONTRADO:");
                    Log::info("   Username: {$user->username}");
                    Log::info("   User ID: {$user->user_id}");
                    Log::info("   Valor a creditar: R$ " . number_format($cashin->deposito_liquido, 2, ',', '.'));
                    
                    Helper::incrementAmount($user, $cashin->deposito_liquido, 'saldo');
                    Helper::calculaSaldoLiquido($user->user_id);
                    
                    Log::info("💰 SALDO CREDITADO COM SUCESSO");

                    // Notificação será enviada automaticamente pelo Observer (SolicitacoesObserver)

                    // Notificar gerente se existir
                    if (isset($user->gerente_id) && !is_null($user->gerente_id)) {
                        Log::info("👨‍💼 GERENTE DETECTADO:");
                        Log::info("   Gerente ID: {$user->gerente_id}");
                        Log::info("   Taxa a creditar: R$ " . number_format($cashin->taxa_cash_in, 2, ',', '.'));
                        
                        $gerente = User::where('user_id', $user->gerente_id)->first();
                        if ($gerente) {
                            Helper::incrementAmount($gerente, $cashin->taxa_cash_in, 'saldo');
                            Helper::calculaSaldoLiquido($gerente->user_id);
                            Log::info("✅ TAXA CREDITADA NO GERENTE: {$gerente->username}");
                            
                            // Enviar notificação de comissão ao gerente
                            $this->pushService->sendCommissionNotification(
                                $gerente->user_id,
                                $cashin->taxa_cash_in,
                                "Comissão de gerente"
                            );
                        }
                    } else {
                        Log::info("ℹ️ Nenhum gerente configurado para este usuário");
                    }

                    Log::info("🎉 DEPÓSITO PROCESSADO COM SUCESSO!");
                } else {
                    Log::error("❌ USUÁRIO NÃO ENCONTRADO:");
                    Log::error("   User ID: {$cashin->user_id}");
                }

                // ENVIAR WEBHOOK PARA O CASSINO (POSTBACK)
                if ($cashin->callback && $cashin->callback != 'web') {
                    $payload = [
                        "status" => "paid",
                        "idTransaction" => $cashin->idTransaction,
                        "typeTransaction" => "RECEIVEPIX",
                        "amount" => $cashin->amount,
                        "date" => $cashin->updated_at->format('Y-m-d H:i:s')
                    ];

                    Log::info('📤 ENVIANDO WEBHOOK PARA O CASSINO:');
                    Log::info('   URL: ' . $cashin->callback);
                    Log::info('   Payload: ' . json_encode($payload, JSON_PRETTY_PRINT));

                    try {
                        $response = Http::withHeaders([
                            'Content-Type' => 'application/json',
                            'accept' => 'application/json'
                        ])->timeout(30)->post($cashin->callback, $payload);

                        Log::info('✅ WEBHOOK ENVIADO COM SUCESSO:');
                        Log::info('   Status Code: ' . $response->status());
                        Log::info('   Resposta: ' . $response->body());
                    } catch (\Exception $e) {
                        Log::error('❌ ERRO AO ENVIAR WEBHOOK:');
                        Log::error('   URL: ' . $cashin->callback);
                        Log::error('   Erro: ' . $e->getMessage());
                    }
                } else {
                    Log::warning('⚠️ WEBHOOK NÃO ENVIADO:');
                    Log::warning('   Motivo: ' . ($cashin->callback ? 'Callback é "web"' : 'Callback não configurado'));
                    Log::warning('   Callback: ' . ($cashin->callback ?? 'NULL'));
                }

                Log::info('🏁 PROCESSAMENTO FINALIZADO COM SUCESSO!');
                return response()->json(['status' => true, 'message' => 'Depósito processado com sucesso']);
            }

            Log::warning("⚠️ STATUS NÃO PROCESSADO:");
            Log::warning("   Status recebido: $status");
            Log::warning("   Status esperados: PAID, paid, APPROVED, COMPLETED");
            Log::warning("   Este status não será processado");
            return response()->json(['status' => true, 'message' => 'Status não processado']);

        } catch (\Exception $e) {
            Log::error('💥 ERRO CRÍTICO NO PROCESSAMENTO:');
            Log::error('   Mensagem: ' . $e->getMessage());
            Log::error('   Arquivo: ' . $e->getFile());
            Log::error('   Linha: ' . $e->getLine());
            Log::error('   Stack Trace: ' . $e->getTraceAsString());
            return response()->json(['status' => false, 'message' => 'Erro interno'], 500);
        }
    }

    /**
     * Callback para saques (Cash-out) - PrimePay7
     */
    public function callbackWithdraw(Request $request)
    {
        try {
            $data = $request->all();
            Log::info('=== WEBHOOK PRIMEPAY7 WITHDRAW RECEBIDO ===');
            Log::info('Timestamp: ' . now()->format('Y-m-d H:i:s'));
            Log::info('IP: ' . $request->ip());
            Log::info('User-Agent: ' . $request->userAgent());
            Log::info('Headers: ' . json_encode($request->headers->all()));
            Log::info('Dados recebidos: ' . json_encode($data, JSON_PRETTY_PRINT));
            Log::info('===========================================');

            // Verifica se é um evento de saque
            $status = null;
            $transactionId = null;
            
            // Formato PrimePay7 - verificar primeiro se tem data.status
            if (isset($data['data']['status']) && isset($data['data']['id'])) {
                $status = $data['data']['status'];
                $transactionId = $data['data']['id'];
                Log::info("🔍 FORMATO PRIMEPAY7 WITHDRAW DETECTADO (data.status):");
                Log::info("   Status: $status");
                Log::info("   Transaction ID: $transactionId");
                Log::info("   Valor: R$ " . number_format($data['data']['amount'] / 100, 2, ',', '.'));
            }
            // Formato PrimePay7 - nível raiz
            elseif (isset($data['status']) && isset($data['transaction_id'])) {
                $status = $data['status'];
                $transactionId = $data['transaction_id'];
                Log::info("🔍 FORMATO PRIMEPAY7 WITHDRAW DETECTADO (nível raiz):");
                Log::info("   Status: $status");
                Log::info("   Transaction ID: $transactionId");
            }
            // Formato alternativo
            elseif (isset($data['event']) && isset($data['data'])) {
                $eventData = $data['data'];
                $status = $eventData['status'] ?? null;
                $transactionId = $eventData['id'] ?? null;
                Log::info("🔍 FORMATO PRIMEPAY7 WITHDRAW DETECTADO (event.data):");
                Log::info("   Status: $status");
                Log::info("   Transaction ID: $transactionId");
            }

            if (!$transactionId || !$status) {
                Log::warning('❌ Callback PrimePay7 Withdraw: Dados insuficientes no callback', $data);
                return response()->json(['status' => false, 'message' => 'Dados insuficientes']);
            }

            Log::info("🔍 Buscando saque com PrimePay7 ID: $transactionId");
            $cashout = SolicitacoesCashOut::where('primepay7_id', $transactionId)->first();
            
            if (!$cashout) {
                Log::error("❌ Callback PrimePay7 Withdraw: Saque não encontrado para PrimePay7 ID: $transactionId");
                return response()->json(['status' => false, 'message' => 'Saque não encontrado']);
            }

            Log::info("✅ SAQUE ENCONTRADO:");
            Log::info("   ID: " . $cashout->id);
            Log::info("   User ID: " . $cashout->user_id);
            Log::info("   Valor: R$ " . number_format($cashout->amount, 2, ',', '.'));
            Log::info("   Valor Líquido: R$ " . number_format($cashout->cash_out_liquido, 2, ',', '.'));
            Log::info("   Status Atual: " . $cashout->status);
            Log::info("   PrimePay7 ID: " . $cashout->primepay7_id);

            // Processar diferentes status
            Log::info("🔄 PROCESSANDO STATUS: $status");
            switch ($status) {
                case 'PAID':
                case 'APPROVED':
                case 'COMPLETED':
                    if ($cashout->status !== "COMPLETED") {
                        $cashout->update(['status' => 'COMPLETED', 'updated_at' => Carbon::now()]);
                        Log::info("✅ Callback PrimePay7 Withdraw: Saque completado - TransactionId: $transactionId");
                    } else {
                        Log::info("ℹ️ Callback PrimePay7 Withdraw: Saque já estava completado - TransactionId: $transactionId");
                    }
                    break;

                case 'FAILED':
                case 'REJECTED':
                case 'CANCELLED':
                    if ($cashout->status !== "FAILED") {
                        $cashout->update(['status' => 'FAILED', 'updated_at' => Carbon::now()]);
                        
                        // Devolver saldo ao usuário
                        $user = User::where('user_id', $cashout->user_id)->first();
                        if ($user) {
                            Helper::incrementAmount($user, $cashout->cash_out_liquido, 'saldo');
                            Helper::calculaSaldoLiquido($user->user_id);
                            Log::info("✅ Callback PrimePay7 Withdraw: Saldo devolvido - User: {$user->username}, Valor: {$cashout->cash_out_liquido}");
                        }
                    } else {
                        Log::info("ℹ️ Callback PrimePay7 Withdraw: Saque já estava falhado - TransactionId: $transactionId");
                    }
                    break;

                case 'PENDING':
                case 'PROCESSING':
                    if ($cashout->status !== "PROCESSING") {
                        $cashout->update(['status' => 'PROCESSING', 'updated_at' => Carbon::now()]);
                        Log::info("✅ Callback PrimePay7 Withdraw: Saque em processamento - TransactionId: $transactionId");
                    } else {
                        Log::info("ℹ️ Callback PrimePay7 Withdraw: Saque já estava em processamento - TransactionId: $transactionId");
                    }
                    break;

                default:
                    Log::warning("⚠️ Callback PrimePay7 Withdraw: Status não processado - Status: $status");
                    break;
            }

            return response()->json(['status' => true, 'message' => 'Callback processado com sucesso']);

        } catch (\Exception $e) {
            Log::error('Callback PrimePay7 Withdraw - Erro: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro interno'], 500);
        }
    }

    /**
     * Callback unificado para PrimePay7 - detecta automaticamente se é depósito ou saque
     */
    public function callbackUnified(Request $request)
    {
        try {
            $data = $request->all();
            Log::info('🔄 CALLBACK UNIFICADO PRIMEPAY7 RECEBIDO');
            Log::info('Timestamp: ' . now()->format('Y-m-d H:i:s'));
            Log::info('Dados: ' . json_encode($data, JSON_PRETTY_PRINT));

            // Detectar tipo de transação baseado no conteúdo
            $transactionType = $this->detectTransactionType($data);
            
            Log::info("🎯 TIPO DE TRANSAÇÃO DETECTADO: $transactionType");

            switch ($transactionType) {
                case 'deposit':
                    return $this->callbackDeposit($request);
                    
                case 'withdraw':
                    return $this->callbackWithdraw($request);
                    
                default:
                    Log::warning("Callback Unificado PrimePay7: Tipo não identificado", $data);
                    return response()->json(['status' => false, 'message' => 'Tipo de transação não identificado'], 400);
            }

        } catch (\Exception $e) {
            Log::error('Callback Unificado PrimePay7 - Erro: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro interno'], 500);
        }
    }

    /**
     * Detecta o tipo de transação baseado no conteúdo do callback
     */
    private function detectTransactionType($data)
    {
        Log::info("🔍 DETECTANDO TIPO DE TRANSAÇÃO:");
        Log::info("   Dados recebidos: " . json_encode($data, JSON_PRETTY_PRINT));

        // PRIMEIRO: Verificar se tem data.status (formato PrimePay7)
        if (isset($data['data']['status'])) {
            $status = strtolower($data['data']['status']);
            $transactionId = $data['data']['id'] ?? null;
            
            Log::info("   Status encontrado em data.status: $status");
            Log::info("   Transaction ID: $transactionId");
            
            // Se tem transaction_id, verificar no banco de dados
            if ($transactionId) {
                // Verificar se é uma solicitação de depósito - buscar pelo ID da PrimePay7
                $deposit = \App\Models\Solicitacoes::where('primepay7_id', $transactionId)->first();
                if ($deposit) {
                    Log::info("   ✅ TRANSAÇÃO DETECTADA COMO DEPÓSITO (encontrada na tabela Solicitacoes pelo primepay7_id)");
                    return 'deposit';
                }
                
                // Verificar se é uma solicitação de saque - buscar pelo ID da PrimePay7
                $withdraw = \App\Models\SolicitacoesCashOut::where('primepay7_id', $transactionId)->first();
                if ($withdraw) {
                    Log::info("   ✅ TRANSAÇÃO DETECTADA COMO SAQUE (encontrada na tabela SolicitacoesCashOut pelo primepay7_id)");
                    return 'withdraw';
                }
            }
            
            // Se não encontrou no banco, usar lógica baseada no status
            if (in_array($status, ['waiting_payment', 'paid', 'completed', 'approved'])) {
                Log::info("   ✅ TRANSAÇÃO DETECTADA COMO DEPÓSITO (baseado no status: $status)");
                return 'deposit';
            }
        }

        // SEGUNDO: Verificar campos específicos que indicam tipo de transação
        if (isset($data['transaction_type'])) {
            $type = strtolower($data['transaction_type']);
            Log::info("   Tipo encontrado em transaction_type: $type");
            return $type === 'deposit' ? 'deposit' : 'withdraw';
        }

        if (isset($data['type'])) {
            $type = strtolower($data['type']);
            Log::info("   Tipo encontrado em type: $type");
            return $type === 'deposit' ? 'deposit' : 'withdraw';
        }

        if (isset($data['event'])) {
            $event = strtolower($data['event']);
            Log::info("   Evento encontrado: $event");
            if (strpos($event, 'deposit') !== false || strpos($event, 'received') !== false) {
                return 'deposit';
            }
            if (strpos($event, 'withdraw') !== false || strpos($event, 'sent') !== false) {
                return 'withdraw';
            }
        }

        // TERCEIRO: Verificar por campos de status que podem indicar tipo
        if (isset($data['status'])) {
            $status = strtolower($data['status']);
            Log::info("   Status encontrado em status: $status");
            // Status que geralmente indicam depósito
            if (in_array($status, ['received', 'credited', 'deposited', 'waiting_payment', 'paid', 'completed', 'approved'])) {
                return 'deposit';
            }
            // Status que geralmente indicam saque
            if (in_array($status, ['sent', 'withdrawn', 'debited'])) {
                return 'withdraw';
            }
        }

        // QUARTO: Verificar por campos de valor (positivo = depósito, negativo = saque)
        if (isset($data['amount'])) {
            $amount = (float) $data['amount'];
            Log::info("   Valor encontrado: $amount");
            if ($amount > 0) {
                return 'deposit';
            } elseif ($amount < 0) {
                return 'withdraw';
            }
        }

        // QUINTO: Verificar por campos de direção
        if (isset($data['direction'])) {
            $direction = strtolower($data['direction']);
            Log::info("   Direção encontrada: $direction");
            return $direction === 'in' ? 'deposit' : 'withdraw';
        }

        if (isset($data['flow'])) {
            $flow = strtolower($data['flow']);
            Log::info("   Fluxo encontrado: $flow");
            return $flow === 'in' ? 'deposit' : 'withdraw';
        }

        // SEXTO: Se não conseguir detectar, tentar verificar se existe transaction_id
        // e consultar no banco de dados para determinar o tipo
        if (isset($data['transaction_id']) || isset($data['id'])) {
            $transactionId = $data['transaction_id'] ?? $data['id'];
            Log::info("   Tentando detectar por transaction_id: $transactionId");
            
            // Verificar se é uma solicitação de depósito - buscar pelo ID da PrimePay7
            $deposit = \App\Models\Solicitacoes::where('primepay7_id', $transactionId)->first();
            if ($deposit) {
                Log::info("   ✅ TRANSAÇÃO DETECTADA COMO DEPÓSITO (encontrada na tabela Solicitacoes pelo primepay7_id)");
                return 'deposit';
            }
            
            // Verificar se é uma solicitação de saque - buscar pelo ID da PrimePay7
            $withdraw = \App\Models\SolicitacoesCashOut::where('primepay7_id', $transactionId)->first();
            if ($withdraw) {
                Log::info("   ✅ TRANSAÇÃO DETECTADA COMO SAQUE (encontrada na tabela SolicitacoesCashOut pelo primepay7_id)");
                return 'withdraw';
            }
        }

        // Se não conseguir detectar, retornar null para indicar erro
        Log::warning("   ❌ NÃO FOI POSSÍVEL DETECTAR O TIPO DE TRANSAÇÃO");
        return null;
    }

    /**
     * Webhook geral para todos os eventos da PrimePay7 (mantido para compatibilidade)
     */
    public function webhook(Request $request)
    {
        try {
            $data = $request->all();
            Log::info('🌐 WEBHOOK GERAL PRIMEPAY7 RECEBIDO');
            Log::info('Timestamp: ' . now()->format('Y-m-d H:i:s'));
            Log::info('Dados: ' . json_encode($data, JSON_PRETTY_PRINT));

            // Determinar o tipo de evento
            $eventType = $data['event'] ?? $data['type'] ?? 'unknown';
            Log::info("🎪 TIPO DE EVENTO DETECTADO: $eventType");
            
            switch ($eventType) {
                case 'payment.received':
                case 'deposit.completed':
                    return $this->callbackDeposit($request);
                    
                case 'payment.sent':
                case 'withdraw.completed':
                case 'withdraw.failed':
                    return $this->callbackWithdraw($request);
                    
                default:
                    Log::info("Webhook PrimePay7: Evento não processado - Tipo: $eventType");
                    return response()->json(['status' => true, 'message' => 'Evento não processado']);
            }

        } catch (\Exception $e) {
            Log::error('Webhook PrimePay7 - Erro: ' . $e->getMessage());
            return response()->json(['status' => false, 'message' => 'Erro interno'], 500);
        }
    }
}
