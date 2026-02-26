<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Repassa o evento de pagamento/saque confirmado para a URL de callback do cliente.
 *
 * Fluxo: Treeal → nossa API → processamento interno → este job → URL do cliente (e-commerce, etc.)
 *
 * Falhas neste job NÃO afetam o processamento financeiro interno. O job loga o erro e encerra
 * sem relançar exceção, evitando retentativas infinitas que poderiam sobrecarregar a fila.
 */
class ClientWebhookDispatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        private string $callbackUrl,
        private string $idTransaction,
        private string $status,
        private float $amount,
        private ?string $paidAt = null,
    ) {}

    public function handle(): void
    {
        if (empty($this->callbackUrl) || $this->callbackUrl === 'web') {
            return;
        }

        $payload = [
            'idTransaction' => $this->idTransaction,
            'status'        => $this->status,
            'amount'        => $this->amount,
            'paidAt'        => $this->paidAt ?? now()->toIso8601String(),
        ];

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($this->callbackUrl, $payload);

            if ($response->successful()) {
                Log::info('[ClientWebhook] Webhook entregue ao cliente', [
                    'url'           => $this->callbackUrl,
                    'idTransaction' => $this->idTransaction,
                    'status'        => $this->status,
                    'http_status'   => $response->status(),
                ]);
            } else {
                Log::warning('[ClientWebhook] Cliente retornou erro ao receber webhook', [
                    'url'           => $this->callbackUrl,
                    'idTransaction' => $this->idTransaction,
                    'status'        => $this->status,
                    'http_status'   => $response->status(),
                    'body'          => substr($response->body(), 0, 500),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('[ClientWebhook] Erro ao disparar webhook para o cliente', [
                'url'           => $this->callbackUrl,
                'idTransaction' => $this->idTransaction,
                'status'        => $this->status,
                'error'         => $e->getMessage(),
            ]);
            // Não relança: falha no webhook do cliente não deve interromper a fila interna.
        }
    }
}
