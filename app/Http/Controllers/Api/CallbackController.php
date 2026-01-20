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

}