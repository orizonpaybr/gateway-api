<?php

namespace App\Services;

use App\Models\WebhookLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service para processamento idempotente de webhooks
 * 
 * Garante que webhooks duplicados não sejam processados múltiplas vezes
 */
class WebhookService
{
    /**
     * Processa webhook de forma idempotente
     * 
     * @param Request $request Request do webhook
     * @param string $adquirente Nome da adquirente (pixup, bspay, etc)
     * @param callable $processor Função que processa o webhook
     * @return \Illuminate\Http\JsonResponse
     */
    public function processWebhook(
        Request $request,
        string $adquirente,
        callable $processor
    ) {
        // Gerar idempotency key
        $idempotencyKey = $this->generateIdempotencyKey($request, $adquirente);
        
        // Verificar se já foi processado
        $existing = WebhookLog::findByKey($idempotencyKey, $adquirente);
        
        if ($existing) {
            if ($existing->status === 'PROCESSED') {
                Log::info("Webhook já processado anteriormente", [
                    'idempotency_key' => $idempotencyKey,
                    'adquirente' => $adquirente,
                    'transaction_id' => $existing->transaction_id,
                ]);
                
                return response()->json([
                    'status' => 'success',
                    'message' => 'Webhook já processado anteriormente'
                ], 200);
            }
            
            // Se está PROCESSING, pode ser requisição duplicada simultânea
            // Aguardar um pouco e verificar novamente
            if ($existing->status === 'PROCESSING') {
                usleep(500000); // 0.5 segundos
                $existing->refresh();
                
                if ($existing->status === 'PROCESSED') {
                    return response()->json([
                        'status' => 'success',
                        'message' => 'Webhook processado por outra requisição simultânea'
                    ], 200);
                }
            }
            
            // Se existe mas não está PROCESSED, usar o registro existente
            $webhookLog = $existing;
        } else {
            // Extrair transaction_id para logging
            $transactionId = $this->extractTransactionId($request);
            
            // Criar registro ANTES de processar (usar firstOrCreate para evitar race condition)
            $webhookLog = WebhookLog::firstOrCreate(
                [
                    'idempotency_key' => $idempotencyKey,
                    'adquirente' => $adquirente,
                ],
                [
                    'transaction_id' => $transactionId,
                    'status' => 'PROCESSING',
                    'payload' => $request->all(),
                ]
            );
            
            // Se já existia (race condition), verificar status
            if ($webhookLog->status === 'PROCESSED') {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Webhook já processado anteriormente'
                ], 200);
            }
            
            // Atualizar payload e transaction_id se necessário
            if ($webhookLog->wasRecentlyCreated === false) {
                $webhookLog->update([
                    'transaction_id' => $transactionId ?? $webhookLog->transaction_id,
                    'payload' => $request->all(),
                ]);
            }
        }
        
        try {
            $result = DB::transaction(function () use ($processor, $webhookLog) {
                // Processar webhook
                $result = $processor();
                
                // Marcar como processado
                $webhookLog->update(['status' => 'PROCESSED']);
                
                return $result;
            });
            
            Log::info("Webhook processado com sucesso", [
                'idempotency_key' => $idempotencyKey,
                'adquirente' => $adquirente,
                'transaction_id' => $transactionId,
            ]);
            
            return $result ?? response()->json(['status' => 'success'], 200);
            
        } catch (\Exception $e) {
            $webhookLog->update([
                'status' => 'FAILED',
                'error' => $e->getMessage(),
            ]);
            
            Log::error("Erro ao processar webhook", [
                'idempotency_key' => $idempotencyKey,
                'adquirente' => $adquirente,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Re-throw para que o controller possa tratar
            throw $e;
        }
    }
    
    /**
     * Gera idempotency key única para o webhook
     */
    private function generateIdempotencyKey(Request $request, string $adquirente): string
    {
        // Tentar obter do header primeiro (padrão)
        $headerKey = $request->header('Idempotency-Key') 
            ?? $request->header('X-Idempotency-Key');
        
        if ($headerKey) {
            return md5($adquirente . ':' . $headerKey);
        }
        
        // Gerar baseado no payload + adquirente
        $payload = $request->all();
        $transactionId = $this->extractTransactionId($request);
        
        // Usar transaction_id + adquirente + timestamp (se disponível)
        $keyData = [
            'adquirente' => $adquirente,
            'transaction_id' => $transactionId,
            'payload_hash' => md5(json_encode($payload)),
        ];
        
        return md5(json_encode($keyData));
    }
    
    /**
     * Extrai transaction_id do request
     * 
     * Suporta múltiplos formatos de diferentes adquirentes:
     * - Treeal: txid, txId
     * - Pagar.me: idTransaction, transaction_id
     * - Outros: id, data.id, etc
     */
    private function extractTransactionId(Request $request): ?string
    {
        $data = $request->all();
        
        return $data['txid'] 
            ?? $data['txId'] 
            ?? $data['idTransaction'] 
            ?? $data['transaction_id'] 
            ?? $data['transactionId']
            ?? $data['data']['id'] 
            ?? $data['data_id'] 
            ?? $data['id']
            ?? $data['requestBody']['external_id'] ?? null;
    }
}
