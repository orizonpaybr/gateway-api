<?php

namespace App\Services;

use App\Models\WebhookLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Service para processamento idempotente de webhooks.
 *
 * Fluxo assíncrono (padrão atual):
 *   1. Cria WebhookLog com status QUEUED (~2 queries).
 *   2. Chama o $processor($webhookLog) passando o log.
 *   3. Se o processor retornar ['async' => true, 'response' => $resp]:
 *      - Responde 200 imediatamente.
 *      - O job é responsável por marcar PROCESSED/FAILED.
 *   4. Se o processor retornar uma JsonResponse (processamento síncrono):
 *      - Marca PROCESSED aqui mesmo.
 *
 * Idempotência:
 *   - PROCESSED ou QUEUED → devolve 200 sem reprocessar.
 *   - FAILED → permite reprocessar (a Treeal reenviou, tentamos de novo).
 */
class WebhookService
{
    /**
     * Processa webhook de forma idempotente.
     *
     * O $processor recebe (WebhookLog $webhookLog) e deve retornar:
     *   - array ['async' => true, 'response' => JsonResponse] para modo assíncrono.
     *   - JsonResponse para modo síncrono (o log é marcado PROCESSED aqui).
     *
     * @param Request  $request
     * @param string   $adquirente
     * @param callable $processor  fn(WebhookLog): JsonResponse|array
     */
    public function processWebhook(
        Request $request,
        string $adquirente,
        callable $processor
    ) {
        $idempotencyKey = $this->generateIdempotencyKey($request, $adquirente);
        $transactionId  = $this->extractTransactionId($request);

        // Verificar se já foi aceito ou processado
        $existing = WebhookLog::findByKey($idempotencyKey, $adquirente);

        if ($existing) {
            if (in_array($existing->status, ['PROCESSED', 'QUEUED', 'PROCESSING'])) {
                Log::info("Webhook já aceito/processado, ignorando duplicata", [
                    'idempotency_key' => $idempotencyKey,
                    'adquirente'      => $adquirente,
                    'status'          => $existing->status,
                    'transaction_id'  => $existing->transaction_id,
                ]);

                return response()->json([
                    'status'  => 'success',
                    'message' => 'Webhook já aceito anteriormente',
                ], 200);
            }

            // FAILED → reutilizar o registro e tentar novamente
            $webhookLog = $existing;
            $webhookLog->update([
                'transaction_id' => $transactionId ?? $webhookLog->transaction_id,
                'payload'        => $request->all(),
                'status'         => 'QUEUED',
                'error'          => null,
            ]);
        } else {
            try {
                // Criar registro (firstOrCreate protege contra race condition)
                $webhookLog = WebhookLog::firstOrCreate(
                    [
                        'idempotency_key' => $idempotencyKey,
                        'adquirente'      => $adquirente,
                    ],
                    [
                        'transaction_id' => $transactionId,
                        'status'         => 'QUEUED',
                        'payload'        => $request->all(),
                    ]
                );
            } catch (\Throwable $e) {
                Log::error('[WebhookService] Falha ao criar WebhookLog (firstOrCreate)', [
                    'idempotency_key' => $idempotencyKey,
                    'adquirente'      => $adquirente,
                    'transaction_id'  => $transactionId,
                    'error'           => $e->getMessage(),
                    'trace'           => $e->getTraceAsString(),
                ]);

                return response()->json(['status' => 'success', 'message' => 'Webhook recebido'], 200);
            }

            // Race condition: outro processo criou antes
            if (!$webhookLog->wasRecentlyCreated) {
                if (in_array($webhookLog->status, ['PROCESSED', 'QUEUED', 'PROCESSING'])) {
                    return response()->json([
                        'status'  => 'success',
                        'message' => 'Webhook já aceito anteriormente',
                    ], 200);
                }
                // Atualizar para QUEUED caso esteja em estado desconhecido
                $webhookLog->update([
                    'transaction_id' => $transactionId ?? $webhookLog->transaction_id,
                    'payload'        => $request->all(),
                    'status'         => 'QUEUED',
                ]);
            }
        }

        try {
            $result = $processor($webhookLog);

            // Modo assíncrono: o job vai marcar PROCESSED/FAILED
            if (is_array($result) && !empty($result['async'])) {
                Log::info("Webhook enfileirado para processamento assíncrono", [
                    'idempotency_key' => $idempotencyKey,
                    'adquirente'      => $adquirente,
                    'transaction_id'  => $transactionId,
                ]);

                return $result['response'] ?? response()->json(['status' => 'success'], 200);
            }

            // Modo síncrono (fallback): marcar PROCESSED aqui
            $webhookLog->update(['status' => 'PROCESSED']);

            Log::info("Webhook processado de forma síncrona", [
                'idempotency_key' => $idempotencyKey,
                'adquirente'      => $adquirente,
                'transaction_id'  => $transactionId,
            ]);

            return $result ?? response()->json(['status' => 'success'], 200);

        } catch (\Throwable $e) {
            $webhookLog->update([
                'status' => 'FAILED',
                'error'  => $e->getMessage(),
            ]);

            Log::error("Erro ao processar webhook", [
                'idempotency_key' => $idempotencyKey,
                'adquirente'      => $adquirente,
                'transaction_id'  => $transactionId,
                'error'           => $e->getMessage(),
                'trace'           => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Gera idempotency key única para o webhook
     */
    private function generateIdempotencyKey(Request $request, string $adquirente): string
    {
        $headerKey = $request->header('Idempotency-Key')
            ?? $request->header('X-Idempotency-Key');

        if ($headerKey) {
            return md5($adquirente . ':' . $headerKey);
        }

        $transactionId = $this->extractTransactionId($request);

        return md5(json_encode([
            'adquirente'    => $adquirente,
            'transaction_id' => $transactionId,
            'payload_hash'  => md5(json_encode($request->all())),
        ]));
    }

    /**
     * Extrai transaction_id do request (suporta formato Treeal e genérico)
     *
     * ONZ envolve o payload em { "data": { "webhookType": "TRANSFER"|"PIX", ... } }.
     * Para TRANSFER (Cash Out) priorizamos endToEndId pois é o campo que gravamos em
     * solicitacoes_cash_out.end_to_end — o campo "id" é um ID interno da ONZ diferente
     * do nosso idTransaction.
     */
    private function extractTransactionId(Request $request): ?string
    {
        $data  = $request->all();
        $inner = isset($data['data']) && is_array($data['data']) ? $data['data'] : null;

        if ($inner) {
            $type = $inner['webhookType'] ?? $data['type'] ?? null;
            if ($type === 'TRANSFER') {
                return $inner['endToEndId']
                    ?? (isset($inner['id']) ? (string) $inner['id'] : null);
            }
            if ($type === 'INFRACTION') {
                return isset($inner['id']) ? (string) $inner['id'] : null;
            }
            return $inner['txid']
                ?? $inner['txId']
                ?? $inner['endToEndId']
                ?? (isset($inner['id']) ? (string) $inner['id'] : null);
        }

        return $data['txid']
            ?? $data['txId']
            ?? $data['idTransaction']
            ?? $data['transaction_id']
            ?? $data['transactionId']
            ?? $data['data_id']
            ?? $data['id']
            ?? $data['requestBody']['external_id']
            ?? null;
    }
}
