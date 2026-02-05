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
use App\Traits\SplitTrait;
use App\Helpers\SecureLog;
use App\Services\TreealService;
use App\Services\PaymentProcessingService;

class CallbackController extends Controller
{
    /**
     * Webhook da Pagar.me com idempotência
     * 
     * REFATORADO: Usa WebhookService para garantir idempotência
     */
    public function webhookPagarme(Request $request)
    {
        $webhookService = app(\App\Services\WebhookService::class);
        
        return $webhookService->processWebhook($request, 'pagarme', function () use ($request) {
            $data = $request->all();
            SecureLog::webhook('PAGARME', 'WEBHOOK', $data);

            $type = $data['type'] ?? null;
            $transaction_id = $data['data']['id'] ?? null;
            
            // Obter status da cobrança/transação
            $chargeStatus = $data['data']['charges'][0]['status'] ?? null;
            $transactionStatus = $data['data']['charges'][0]['last_transaction']['status'] ?? null;
            $paymentMethod = $data['data']['charges'][0]['payment_method'] ?? 'pix';

            Log::debug("[PAGAR.ME] WEBHOOK RECEBIDO", [
                'type' => $type,
                'transaction_id' => $transaction_id,
                'charge_status' => $chargeStatus,
                'transaction_status' => $transactionStatus,
                'payment_method' => $paymentMethod
            ]);

            // Determinar tipo de transação (PIX ou CARTÃO)
            $isCardPayment = $paymentMethod === 'credit_card';
            $typeTransaction = $isCardPayment ? 'CARD' : 'PIX';

            // Processar eventos de pagamento
            switch ($type) {
                case 'order.paid':
                    return $this->handlePagarmeOrderPaid($transaction_id, $typeTransaction, $data);
                    
                case 'order.payment_failed':
                    return $this->handlePagarmePaymentFailed($transaction_id, $typeTransaction, $data);
                    
                case 'charge.refunded':
                case 'charge.partial_refunded':
                    return $this->handlePagarmeRefund($transaction_id, $data);
                    
                case 'charge.chargedback':
                    return $this->handlePagarmeChargeback($transaction_id, $data);
                    
                default:
                    Log::debug("[PAGAR.ME] Evento não tratado: {$type}");
                    return response()->json(['status' => true, 'message' => 'Evento recebido']);
            }
        });
    }

    /**
     * Processa pagamento aprovado da Pagar.me (PIX ou Cartão)
     * 
     * REFATORADO: Usa PaymentProcessingService para operação atômica e thread-safe
     */
    private function handlePagarmeOrderPaid($transaction_id, $typeTransaction, $data)
    {
        $cashin = Solicitacoes::where('idTransaction', $transaction_id)->first();
        
        if (!$cashin) {
            Log::warning("[PAGAR.ME] Solicitação não encontrada: {$transaction_id}");
            return response()->json(['status' => false, 'message' => 'Transação não encontrada']);
        }

        Log::debug("[PAGAR.ME] Processando pagamento aprovado", [
            'transaction_id' => $transaction_id,
            'type' => $typeTransaction,
            'amount' => $cashin->amount
        ]);

        try {
            // Atualizar end_to_end antes de processar
            if (isset($data['data']['charges'][0]['last_transaction']['acquirer_nsu'])) {
                $cashin->update([
                    'end_to_end' => $data['data']['charges'][0]['last_transaction']['acquirer_nsu']
                ]);
            }

            // Processar pagamento de forma atômica (com locks e transação)
            $paymentService = app(\App\Services\PaymentProcessingService::class);
            $paymentService->processPaymentReceived($cashin);
            
            Log::info("[PAGAR.ME] Pagamento processado com sucesso", [
                'transaction_id' => $transaction_id,
            ]);
            
        } catch (\Exception $e) {
            Log::error("[PAGAR.ME] Erro ao processar pagamento", [
                'transaction_id' => $transaction_id,
                'error' => $e->getMessage(),
            ]);
            
            // Se já foi processado (idempotência), retornar sucesso
            $cashin->refresh();
            if ($cashin->status === 'PAID_OUT' || $cashin->status === 'COMPLETED') {
                return response()->json(['status' => true, 'message' => 'Já processado']);
            }
            
            throw $e;
        }

        // Buscar usuário atualizado para operações pós-processamento
        $cashin->refresh();
        $user = User::where('user_id', $cashin->user_id)->first();

        // Atualizar pedido de checkout se existir
        $order = CheckoutOrders::where('idTransaction', $transaction_id)->first();
        if ($order) {
            $order->update(['status' => 'pago']);
            // Webhook do cliente será enviado em background (implementar depois)
        }

        return response()->json(['status' => true]);
    }

    /**
     * Processa falha de pagamento da Pagar.me
     */
    private function handlePagarmePaymentFailed($transaction_id, $typeTransaction, $data)
    {
        $cashin = Solicitacoes::where('idTransaction', $transaction_id)->first();
        
        if (!$cashin) {
            return response()->json(['status' => false, 'message' => 'Transação não encontrada']);
        }

        $refusalReason = $data['data']['charges'][0]['last_transaction']['gateway_response']['errors'][0]['message'] ?? 'Pagamento recusado';

        Log::warning("[PAGAR.ME] Pagamento falhou", [
            'transaction_id' => $transaction_id,
            'reason' => $refusalReason
        ]);

        $cashin->update([
            'status' => 'FAILED',
            'updated_at' => Carbon::now(),
            'descricao' => $refusalReason,
        ]);

        // Enviar callback de falha
        if ($cashin->callback && $cashin->callback != 'web') {
            Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->post($cashin->callback, [
                "status" => "failed",
                "idTransaction" => $cashin->idTransaction,
                "typeTransaction" => $typeTransaction,
                "reason" => $refusalReason,
            ]);
        }

        return response()->json(['status' => true]);
    }

    /**
     * Processa estorno da Pagar.me
     */
    private function handlePagarmeRefund($transaction_id, $data)
    {
        $cashin = Solicitacoes::where('idTransaction', $transaction_id)->first();
        
        if (!$cashin) {
            return response()->json(['status' => false, 'message' => 'Transação não encontrada']);
        }

        $refundAmount = ($data['data']['charges'][0]['last_transaction']['amount'] ?? 0) / 100;
        $isPartial = $refundAmount < $cashin->amount;

        Log::info("[PAGAR.ME] Estorno processado", [
            'transaction_id' => $transaction_id,
            'refund_amount' => $refundAmount,
            'is_partial' => $isPartial
        ]);

        // Reverter saldo do usuário
        $user = User::where('user_id', $cashin->user_id)->first();
        if ($user && $cashin->status === 'PAID_OUT') {
            $amountToDeduct = $isPartial ? $refundAmount : $cashin->deposito_liquido;
            Helper::decrementAmount($user, $amountToDeduct, 'saldo');
            Helper::calculaSaldoLiquido($user->user_id);
        }

        $cashin->update([
            'status' => $isPartial ? 'PARTIAL_REFUNDED' : 'REFUNDED',
            'updated_at' => Carbon::now(),
        ]);

        // Enviar callback de estorno
        if ($cashin->callback && $cashin->callback != 'web') {
            Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->post($cashin->callback, [
                "status" => "refunded",
                "idTransaction" => $cashin->idTransaction,
                "refund_amount" => $refundAmount,
                "is_partial" => $isPartial,
            ]);
        }

        return response()->json(['status' => true]);
    }

    /**
     * Processa chargeback da Pagar.me
     */
    private function handlePagarmeChargeback($transaction_id, $data)
    {
        $cashin = Solicitacoes::where('idTransaction', $transaction_id)->first();
        
        if (!$cashin) {
            return response()->json(['status' => false, 'message' => 'Transação não encontrada']);
        }

        Log::warning("[PAGAR.ME] CHARGEBACK recebido", [
            'transaction_id' => $transaction_id,
            'amount' => $cashin->amount
        ]);

        // Reverter saldo do usuário
        $user = User::where('user_id', $cashin->user_id)->first();
        if ($user && $cashin->status === 'PAID_OUT') {
            Helper::decrementAmount($user, $cashin->deposito_liquido, 'saldo');
            Helper::calculaSaldoLiquido($user->user_id);
        }

        $cashin->update([
            'status' => 'CHARGEBACK',
            'updated_at' => Carbon::now(),
        ]);

        // Enviar callback de chargeback
        if ($cashin->callback && $cashin->callback != 'web') {
            Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->post($cashin->callback, [
                "status" => "chargeback",
                "idTransaction" => $cashin->idTransaction,
                "amount" => $cashin->amount,
            ]);
        }

        return response()->json(['status' => true]);
    }

    /**
     * Webhook da Treeal/ONZ para depósitos PIX (Cash In)
     * 
     * Implementação limpa seguindo padrão PIX do Banco Central
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function webhookTreeal(Request $request)
    {
        $webhookService = app(\App\Services\WebhookService::class);
        
        return $webhookService->processWebhook($request, 'treeal', function () use ($request) {
            $data = $request->all();
            SecureLog::webhook('TREEAL', 'WEBHOOK', $data);

            Log::info('[TREEAL] Webhook recebido', [
                'data' => $data
            ]);

            // Treeal pode enviar webhooks em diferentes formatos
            // Verificar se é evento de cobrança (Cash In) ou pagamento (Cash Out)
            $txid = $data['txid'] ?? $data['txId'] ?? $data['idTransaction'] ?? null;
            $status = $data['status'] ?? $data['paymentStatus'] ?? null;
            $endToEndId = $data['endToEndId'] ?? $data['end_to_end_id'] ?? null;

            // Se for evento de cobrança (Cash In)
            if (isset($data['txid']) || isset($data['txId'])) {
                return $this->handleTreealCashInWebhook($txid, $status, $data);
            }

            // Se for evento de pagamento (Cash Out)
            if (isset($data['transactionId']) || isset($endToEndId)) {
                return $this->handleTreealCashOutWebhook($txid ?? $data['transactionId'], $status, $data);
            }

            Log::warning('[TREEAL] Webhook com formato desconhecido', [
                'data' => $data
            ]);

            return response()->json(['status' => true, 'message' => 'Webhook recebido']);
        });
    }

    /**
     * Processa webhook de depósito PIX (Cash In) da Treeal
     */
    private function handleTreealCashInWebhook($txid, $status, $data)
    {
        if (!$txid) {
            Log::warning('[TREEAL] Webhook Cash In sem txid', ['data' => $data]);
            return response()->json(['status' => false, 'message' => 'txid não encontrado'], 400);
        }

        $cashin = Solicitacoes::where('idTransaction', $txid)
            ->orWhere('externalreference', $txid)
            ->first();

        if (!$cashin) {
            Log::warning('[TREEAL] Solicitação não encontrada', ['txid' => $txid]);
            return response()->json(['status' => false, 'message' => 'Transação não encontrada'], 404);
        }

        Log::info('[TREEAL] Processando webhook Cash In', [
            'txid' => $txid,
            'status' => $status,
            'current_status' => $cashin->status
        ]);

        // Mapear status da Treeal para status interno
        $internalStatus = $this->mapTreealStatusToInternal($status);

        // Se status for CONCLUIDA/ATIVA e ainda não foi processado
        if (in_array(strtoupper($status ?? ''), ['CONCLUIDA', 'ATIVA', 'PAID', 'COMPLETED']) 
            && $cashin->status !== 'PAID_OUT') {
            
            // Atualizar end_to_end se disponível
            if (isset($data['endToEndId'])) {
                $cashin->update(['end_to_end' => $data['endToEndId']]);
            }

            // Processar pagamento de forma atômica (thread-safe)
            try {
                $paymentService = app(PaymentProcessingService::class);
                $paymentService->processPaymentReceived($cashin);
                
                Log::info('[TREEAL] Pagamento processado com sucesso', [
                    'txid' => $txid,
                    'amount' => $cashin->amount
                ]);
            } catch (\Exception $e) {
                Log::error('[TREEAL] Erro ao processar pagamento', [
                    'txid' => $txid,
                    'error' => $e->getMessage()
                ]);
                // Se já foi processado, continuar normalmente
                $cashin->refresh();
                if ($cashin->status !== 'PAID_OUT') {
                    throw $e;
                }
            }
        } else {
            // Atualizar apenas o status se necessário
            if ($cashin->status !== $internalStatus) {
                $cashin->update(['status' => $internalStatus]);
            }
        }

        return response()->json(['status' => true, 'message' => 'Webhook processado']);
    }

    /**
     * Processa webhook de saque PIX (Cash Out) da Treeal
     * 
     * Status possíveis da TREEAL (API ONZ):
     * - PROCESSING: Em processamento
     * - LIQUIDATED: Transação liquidada com sucesso
     * - CANCELED: Transação cancelada
     * - REFUNDED: Transação estornada
     * - PARTIALLY_REFUNDED: Parcialmente estornada
     */
    /**
     * Processa webhook de Cash Out (saque) da Treeal
     * 
     * CORRIGIDO: Agora processa o saldo corretamente quando:
     * - Saque é confirmado (LIQUIDATED/COMPLETED) - debita saldo se ainda não foi debitado
     * - Saque é cancelado (CANCELLED) - reverte saldo se já foi debitado
     * - Saque é estornado (REFUNDED) - reverte saldo se já foi debitado
     */
    private function handleTreealCashOutWebhook($transactionId, $status, $data)
    {
        if (!$transactionId) {
            Log::warning('[TREEAL] Webhook Cash Out sem transactionId', ['data' => $data]);
            return response()->json(['status' => false, 'message' => 'transactionId não encontrado'], 400);
        }

        // Tentar buscar também pelo endToEndId se disponível
        $endToEndId = $data['endToEndId'] ?? $data['end_to_end_id'] ?? null;
        
        $cashOut = SolicitacoesCashOut::where('idTransaction', $transactionId)
            ->orWhere('externalreference', $transactionId)
            ->when($endToEndId, function($query) use ($endToEndId) {
                $query->orWhere('end_to_end', $endToEndId);
            })
            ->first();

        if (!$cashOut) {
            Log::warning('[TREEAL] Saque não encontrado', [
                'transaction_id' => $transactionId,
                'end_to_end_id' => $endToEndId
            ]);
            return response()->json(['status' => false, 'message' => 'Saque não encontrado'], 404);
        }

        Log::info('[TREEAL] Processando webhook Cash Out', [
            'transaction_id' => $transactionId,
            'status' => $status,
            'current_status' => $cashOut->status,
            'end_to_end_id' => $endToEndId
        ]);

        // Mapear status da Treeal para status interno
        $internalStatus = $this->mapTreealStatusToInternal($status);
        $statusUpper = strtoupper($status ?? '');

        // Status que indicam saque confirmado/liquidado
        $statusConfirmado = ['LIQUIDATED', 'COMPLETED', 'PAID', 'CONCLUIDO'];
        // Status que indicam saque cancelado
        $statusCancelado = ['CANCELED', 'CANCELLED', 'FAILED'];
        // Status que indicam saque estornado
        $statusEstornado = ['REFUNDED', 'PARTIALLY_REFUNDED'];
        
        // Preparar dados para atualização
        $updateData = [];
        
        // Salvar endToEndId se disponível
        if ($endToEndId && empty($cashOut->end_to_end)) {
            $updateData['end_to_end'] = $endToEndId;
        }

        // ========================================
        // PROCESSAMENTO DE SAQUE CONFIRMADO
        // ========================================
        if (in_array($statusUpper, $statusConfirmado)) {
            // Verificar se já foi processado (idempotência)
            if (in_array($cashOut->status, ['PAID_OUT', 'COMPLETED'])) {
                Log::info('[TREEAL] Saque já processado anteriormente', [
                    'transaction_id' => $transactionId,
                    'status' => $cashOut->status
                ]);
                
                // Atualizar end_to_end se necessário
                if (!empty($updateData)) {
                    $cashOut->update($updateData);
                }
                
                return response()->json(['status' => true, 'message' => 'Já processado']);
            }
            
            try {
                // Usar PaymentProcessingService para processar de forma atômica
                $paymentService = app(PaymentProcessingService::class);
                $paymentService->processWithdrawal($cashOut);
                
                // Atualizar executor_ordem para indicar que foi processado pela Treeal
                $cashOut->update([
                    'executor_ordem' => 'Treeal',
                    'end_to_end' => $endToEndId ?? $cashOut->end_to_end
                ]);
                
                Log::info('[TREEAL] Saque confirmado e processado com sucesso', [
                    'transaction_id' => $transactionId,
                    'amount' => $cashOut->amount,
                    'user_id' => $cashOut->user_id
                ]);
                
                return response()->json(['status' => true, 'message' => 'Saque processado']);
                
            } catch (\Exception $e) {
                // Verificar se já foi processado (idempotência - pode ter sido processado por outra requisição)
                $cashOut->refresh();
                if (in_array($cashOut->status, ['PAID_OUT', 'COMPLETED'])) {
                    Log::info('[TREEAL] Saque processado por outra requisição', [
                        'transaction_id' => $transactionId
                    ]);
                    return response()->json(['status' => true, 'message' => 'Já processado']);
                }
                
                Log::error('[TREEAL] Erro ao processar saque confirmado', [
                    'transaction_id' => $transactionId,
                    'error' => $e->getMessage()
                ]);
                
                return response()->json([
                    'status' => false, 
                    'message' => 'Erro ao processar saque: ' . $e->getMessage()
                ], 500);
            }
        }
        
        // ========================================
        // PROCESSAMENTO DE SAQUE CANCELADO
        // ========================================
        if (in_array($statusUpper, $statusCancelado)) {
            Log::warning('[TREEAL] Saque cancelado', [
                'transaction_id' => $transactionId,
                'reason' => $data['message'] ?? $data['errorCode'] ?? 'Não informado',
                'current_status' => $cashOut->status
            ]);
            
            // Se o saque estava em processamento ou já foi debitado, reverter o saldo
            if (in_array($cashOut->status, ['PROCESSING', 'PAID_OUT', 'COMPLETED'])) {
                try {
                    $this->reverterSaldoSaque($cashOut, $transactionId, 'cancelamento');
                } catch (\Exception $e) {
                    Log::error('[TREEAL] Erro ao reverter saldo por cancelamento', [
                        'transaction_id' => $transactionId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Atualizar status para cancelado
            $cashOut->update([
                'status' => 'CANCELLED',
                'end_to_end' => $endToEndId ?? $cashOut->end_to_end
            ]);
            
            return response()->json(['status' => true, 'message' => 'Saque cancelado processado']);
        }
        
        // ========================================
        // PROCESSAMENTO DE SAQUE ESTORNADO
        // ========================================
        if (in_array($statusUpper, $statusEstornado)) {
            Log::warning('[TREEAL] Saque estornado', [
                'transaction_id' => $transactionId,
                'status' => $status,
                'end_to_end_id' => $endToEndId,
                'data' => $data
            ]);
            
            // Se o saque foi pago/completado, reverter o saldo
            if (in_array($cashOut->status, ['PAID_OUT', 'COMPLETED'])) {
                try {
                    $isPartial = $statusUpper === 'PARTIALLY_REFUNDED';
                    $refundAmount = $isPartial ? ($data['refundAmount'] ?? $data['amount'] ?? $cashOut->amount) : $cashOut->amount;
                    
                    $this->reverterSaldoSaque($cashOut, $transactionId, 'estorno', $refundAmount);
                } catch (\Exception $e) {
                    Log::error('[TREEAL] Erro ao reverter saldo por estorno', [
                        'transaction_id' => $transactionId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Atualizar status para estornado
            $cashOut->update([
                'status' => $internalStatus,
                'end_to_end' => $endToEndId ?? $cashOut->end_to_end
            ]);
            
            return response()->json(['status' => true, 'message' => 'Saque estornado processado']);
        }

        // ========================================
        // OUTROS STATUS (PROCESSING, etc.)
        // ========================================
        if ($cashOut->status !== $internalStatus) {
            $updateData['status'] = $internalStatus;
        }
        
        // Aplicar atualizações se houver
        if (!empty($updateData)) {
            $cashOut->update($updateData);
            
            Log::info('[TREEAL] Saque atualizado', [
                'transaction_id' => $transactionId,
                'updates' => array_keys($updateData)
            ]);
        }

        return response()->json(['status' => true, 'message' => 'Webhook processado']);
    }

    /**
     * Reverte o saldo de um saque cancelado ou estornado
     * 
     * @param SolicitacoesCashOut $cashOut
     * @param string $transactionId
     * @param string $motivo 'cancelamento' ou 'estorno'
     * @param float|null $valorEstornado Valor a reverter (para estornos parciais)
     */
    private function reverterSaldoSaque(SolicitacoesCashOut $cashOut, string $transactionId, string $motivo, ?float $valorEstornado = null)
    {
        $user = User::where('user_id', $cashOut->user_id)->first();
        
        if (!$user) {
            Log::warning("[TREEAL] Usuário não encontrado para reverter saldo de {$motivo}", [
                'transaction_id' => $transactionId,
                'user_id' => $cashOut->user_id
            ]);
            return;
        }
        
        // Calcular valor a reverter
        $valorPrincipal = $valorEstornado ?? $cashOut->amount;
        $valorTaxas = $cashOut->taxa_cash_out ?? 0;
        $valorTotalReverter = $valorPrincipal + $valorTaxas;
        
        // Reverter saldo
        $balanceService = app(\App\Services\BalanceService::class);
        $balanceService->incrementBalance($user, $valorTotalReverter, 'saldo');
        
        // Recalcular saldo líquido
        Helper::calculaSaldoLiquido($user->user_id);
        
        Log::info("[TREEAL] Saldo revertido por {$motivo}", [
            'transaction_id' => $transactionId,
            'user_id' => $user->user_id,
            'valor_principal' => $valorPrincipal,
            'valor_taxas' => $valorTaxas,
            'valor_total_revertido' => $valorTotalReverter,
            'saldo_atualizado' => $user->fresh()->saldo
        ]);
    }

    /**
     * Mapeia status da Treeal para status interno
     * 
     * Status TREEAL (Cash In - API QRCodes):
     * - ATIVA: Cobrança ativa aguardando pagamento
     * - CONCLUIDA: Cobrança paga
     * - REMOVIDA_PELO_USUARIO_RECEBEDOR: Cobrança removida/cancelada
     * - EM_PROCESSAMENTO: Em processamento
     * - NAO_REALIZADO: Não realizado/falhou
     * 
     * Status TREEAL (Cash Out - API ONZ):
     * - PROCESSING: Em processamento
     * - LIQUIDATED: Liquidado com sucesso
     * - CANCELED: Cancelado
     * - REFUNDED: Estornado
     */
    private function mapTreealStatusToInternal(string $status): string
    {
        $statusUpper = strtoupper($status);
        
        $statusMap = [
            // Cash In - Status de cobrança
            'ATIVA' => 'WAITING_FOR_APPROVAL',
            'CONCLUIDA' => 'PAID_OUT',
            'REMOVIDA_PELO_USUARIO_RECEBEDOR' => 'CANCELLED',
            'EM_PROCESSAMENTO' => 'PROCESSING',
            'NAO_REALIZADO' => 'FAILED',
            
            // Status genéricos (Cash In e Cash Out)
            'PAID' => 'PAID_OUT',
            'COMPLETED' => 'PAID_OUT',
            'PROCESSING' => 'PROCESSING',
            'FAILED' => 'FAILED',
            'CANCELLED' => 'CANCELLED',
            'CANCELED' => 'CANCELLED',
            
            // Cash Out - Status específicos
            'LIQUIDATED' => 'PAID_OUT',
            'REFUNDED' => 'REFUNDED',
            'PARTIALLY_REFUNDED' => 'PARTIALLY_REFUNDED',
        ];

        return $statusMap[$statusUpper] ?? 'PENDING';
    }

}