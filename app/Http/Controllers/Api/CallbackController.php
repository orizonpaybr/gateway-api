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
use App\Helpers\SecureLog;
use App\Jobs\ProcessTreealCashInJob;
use App\Jobs\ProcessTreealCashOutJob;
use App\Services\PaymentProcessingService;

class CallbackController extends Controller
{
    /**
     * Webhook da Treeal/ONZ para depósitos PIX (Cash In) e saques (Cash Out).
     *
     * Fluxo assíncrono:
     * 1. WebhookService cria WebhookLog (QUEUED) — ~2 queries.
     * 2. Job é despachado para a fila.
     * 3. Responde 200 à Treeal em ~50–100 ms.
     * 4. Job processa pagamento/saque em background e marca PROCESSED/FAILED.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function webhookTreeal(Request $request)
    {
        $start          = microtime(true);
        $webhookService = app(\App\Services\WebhookService::class);

        return $webhookService->processWebhook($request, 'treeal', function ($webhookLog) use ($request, $start) {
            $data = $request->all();
            SecureLog::webhook('TREEAL', 'WEBHOOK', $data);

            $txid       = $data['txid'] ?? $data['txId'] ?? $data['idTransaction'] ?? null;
            $status     = $data['status'] ?? $data['paymentStatus'] ?? null;
            $endToEndId = $data['endToEndId'] ?? $data['end_to_end_id'] ?? null;

            // ── Cash In (depósito) ────────────────────────────────────────────
            if (isset($data['txid']) || isset($data['txId'])) {
                return $this->handleTreealCashInWebhook($txid, $status, $data, $webhookLog, $start);
            }

            // ── Cash Out (saque) ──────────────────────────────────────────────
            if (isset($data['transactionId']) || isset($endToEndId)) {
                return $this->handleTreealCashOutWebhook(
                    $txid ?? $data['transactionId'],
                    $status,
                    $data,
                    $webhookLog,
                    $start
                );
            }

            Log::warning('[TREEAL] Webhook com formato desconhecido', ['data' => $data]);

            return response()->json(['status' => true, 'message' => 'Webhook recebido']);
        });
    }

    /**
     * Processa webhook de depósito PIX (Cash In) da Treeal — modo assíncrono.
     *
     * Verifica se o pagamento é confirmado; se sim, despacha ProcessTreealCashInJob
     * e retorna 200 imediatamente. O crédito ao saldo ocorre via job worker.
     */
    private function handleTreealCashInWebhook($txid, $status, $data, $webhookLog, float $start)
    {
        if (!$txid) {
            Log::warning('[TREEAL] Webhook Cash In sem txid', ['data' => $data]);
            return response()->json(['status' => false, 'message' => 'txid não encontrado'], 400);
        }

        // Treeal pode enviar webhook sem campo "status" → considerar CONCLUIDA
        $statusNormalized   = $status !== null && $status !== '' ? strtoupper((string) $status) : 'CONCLUIDA';
        $isPaymentConfirmed = in_array($statusNormalized, ['CONCLUIDA', 'ATIVA', 'PAID', 'COMPLETED']);

        if ($isPaymentConfirmed) {
            // Enfileirar processamento assíncrono
            ProcessTreealCashInJob::dispatch($txid, $webhookLog->id)->onQueue('webhooks');

            $durationMs = round((microtime(true) - $start) * 1000, 2);
            Log::info('[TREEAL] Cash In enfileirado', [
                'txid'        => $txid,
                'status'      => $status,
                'duration_ms' => $durationMs,
            ]);

            return ['async' => true, 'response' => response()->json(['status' => true, 'message' => 'Webhook aceito'])];
        }

        // Pagamento ainda não confirmado → atualizar status apenas
        $internalStatus = $this->mapTreealStatusToInternal($status);
        $cashin = Solicitacoes::where('idTransaction', $txid)
            ->orWhere('externalreference', $txid)
            ->first();

        if ($cashin && $cashin->status !== $internalStatus) {
            $cashin->update(['status' => $internalStatus]);
        }

        Log::info('[TREEAL] Cash In status intermediário', [
            'txid'        => $txid,
            'status'      => $status,
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        ]);

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
     * Processa webhook de Cash Out (saque) da Treeal — modo assíncrono.
     *
     * Despacha ProcessTreealCashOutJob para a fila e responde 200 imediatamente.
     * O processamento de saldo (débito, cancelamento, estorno) ocorre via job worker.
     */
    private function handleTreealCashOutWebhook($transactionId, $status, $data, $webhookLog, float $start)
    {
        if (!$transactionId) {
            Log::warning('[TREEAL] Webhook Cash Out sem transactionId', ['data' => $data]);
            return response()->json(['status' => false, 'message' => 'transactionId não encontrado'], 400);
        }

        // Enfileirar processamento assíncrono
        ProcessTreealCashOutJob::dispatch($transactionId, $status, $data, $webhookLog->id)->onQueue('webhooks');

        $durationMs = round((microtime(true) - $start) * 1000, 2);
        Log::info('[TREEAL] Cash Out enfileirado', [
            'transaction_id' => $transactionId,
            'status'         => $status,
            'duration_ms'    => $durationMs,
        ]);

        return ['async' => true, 'response' => response()->json(['status' => true, 'message' => 'Webhook aceito'])];
    }

    /**
     * Lógica completa de Cash Out — mantida para referência, agora executada via ProcessTreealCashOutJob.
     *
     * @deprecated Use ProcessTreealCashOutJob
     */
    private function handleTreealCashOutWebhookSync($transactionId, $status, $data)
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
    private function mapTreealStatusToInternal(?string $status): string
    {
        if ($status === null || $status === '') {
            return 'WAITING_FOR_APPROVAL';
        }

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