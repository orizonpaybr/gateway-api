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

    public function webhookPagarme(Request $request)
    {
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
    }

    /**
     * Processa pagamento aprovado da Pagar.me (PIX ou Cartão)
     */
    private function handlePagarmeOrderPaid($transaction_id, $typeTransaction, $data)
    {
        $cashin = Solicitacoes::where('idTransaction', $transaction_id)->first();
        
        if (!$cashin) {
            Log::warning("[PAGAR.ME] Solicitação não encontrada: {$transaction_id}");
            return response()->json(['status' => false, 'message' => 'Transação não encontrada']);
        }

        // Verificar se já foi processado
        if (in_array($cashin->status, ['PAID_OUT', 'COMPLETED'])) {
            Log::debug("[PAGAR.ME] Transação já processada: {$transaction_id}");
            return response()->json(['status' => true, 'message' => 'Já processado']);
        }

        Log::debug("[PAGAR.ME] Processando pagamento aprovado", [
            'transaction_id' => $transaction_id,
            'type' => $typeTransaction,
            'amount' => $cashin->amount
        ]);

        // Atualizar status
        $cashin->update([
            'status' => 'PAID_OUT',
            'updated_at' => Carbon::now(),
            'end_to_end' => $data['data']['charges'][0]['last_transaction']['acquirer_nsu'] ?? null,
        ]);

        // Creditar saldo do usuário
        $user = User::where('user_id', $cashin->user_id)->first();
        if ($user) {
            Helper::incrementAmount($user, $cashin->deposito_liquido, 'saldo');
            Helper::calculaSaldoLiquido($user->user_id);
        }

        // Processar comissão do gerente
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

        // Atualizar pedido de checkout se existir
        $order = CheckoutOrders::where('idTransaction', $transaction_id)->first();
        if ($order) {
            $order->update(['status' => 'pago']);
            if ($user && !is_null($user->webhook_url) && in_array('pago', (array) $user->webhook_endpoint)) {
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

        // Enviar callback ao cliente
        if ($cashin->callback && $cashin->callback != 'web') {
            $payload = [
                "status" => "paid",
                "idTransaction" => $cashin->idTransaction,
                "typeTransaction" => $typeTransaction,
                "amount" => $cashin->amount,
                "net_amount" => $cashin->deposito_liquido,
            ];

            Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->post($cashin->callback, $payload);

            Log::debug("[PAGAR.ME] Callback enviado para: {$cashin->callback}");
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