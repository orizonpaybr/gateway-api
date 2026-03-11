<?php

namespace App\Jobs;

use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
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
 * Fluxo: Adquirente (HeartPay) → nossa API → processamento interno → este job → URL do cliente (e-commerce, etc.)
 *
 * Falhas neste job NÃO afetam o processamento financeiro interno. O job loga o erro e encerra
 * sem relançar exceção, evitando retentativas infinitas que poderiam sobrecarregar a fila.
 */
class ClientWebhookDispatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    /** Backoff exponencial (s): 1ª retry 10s, 2ª 30s, 3ª 90s */
    public array $backoff = [10, 30, 90];

    /**
     * @param array<string, mixed> $extraPayload Campos adicionais (ex.: typeTransaction, payer, beneficiary, receiver, sender). Apenas chaves com valor não vazio são enviadas.
     * @param string|null $message Mensagem amigável do status para o cliente (ex.: "Depósito PIX recebido com sucesso.").
     */
    public function __construct(
        private string $callbackUrl,
        private string $idTransaction,
        private string $status,
        private float $amount,
        private ?string $paidAt = null,
        private array $extraPayload = [],
        private ?string $message = null,
    ) {}

    public function handle(): void
    {
        if (empty($this->callbackUrl) || $this->callbackUrl === 'web') {
            return;
        }

        $payload = array_merge(
            [
                'idTransaction' => $this->idTransaction,
                'status'        => $this->status,
                'amount'        => $this->amount,
                'paidAt'        => $this->paidAt ?? now()->toIso8601String(),
            ],
            $this->filterEmpty($this->extraPayload)
        );
        if ($this->message !== null && $this->message !== '') {
            $payload['message'] = $this->message;
        }

        $logContext = [
            'method'        => 'POST',
            'url'           => $this->callbackUrl,
            'request_body'  => $payload,
        ];

        try {
            $response = Http::timeout(10)
                ->withoutRedirecting()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($this->callbackUrl, $payload);

            $responseBody = substr($response->body(), 0, 1000);

            $logContext['http_status']   = $response->status();
            $logContext['response_body'] = $responseBody;

            if ($response->successful()) {
                Log::info('[ClientWebhook] Webhook entregue ao cliente', $logContext);
                $this->recordDelivery('delivered', $response->status(), null, $payload);
            } elseif ($response->redirect()) {
                Log::warning('[ClientWebhook] Cliente retornou redirect — verificar URL de callback', $logContext);
                $this->recordDelivery('redirect', $response->status(), 'Servidor retornou redirect ' . $response->status(), $payload);
            } else {
                Log::warning('[ClientWebhook] Cliente retornou erro ao receber webhook', $logContext);
                $this->recordDelivery('error', $response->status(), "HTTP {$response->status()}: " . substr($responseBody, 0, 200), $payload);
            }
        } catch (\Throwable $e) {
            $logContext['error'] = $e->getMessage();
            Log::error('[ClientWebhook] Erro ao disparar webhook para o cliente', $logContext);
            $this->recordDelivery('failed', null, substr($e->getMessage(), 0, 200), $payload);
        }
    }

    /**
     * Grava resultado da entrega do webhook na transação (Cash In ou Cash Out).
     * Inclui o payload enviado para o cliente poder auditar "payload de entrada" na consulta de status.
     */
    private function recordDelivery(string $status, ?int $httpStatus, ?string $error = null, ?array $payload = null): void
    {
        $requestBodyJson = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;
        if ($requestBodyJson !== null && strlen($requestBodyJson) > 4000) {
            $requestBodyJson = substr($requestBodyJson, 0, 4000);
        }

        $data = [
            'webhook_status'        => $status,
            'webhook_sent_at'       => now(),
            'webhook_http_status'   => $httpStatus,
            'webhook_error'         => $error,
            'webhook_request_body'  => $requestBodyJson,
        ];

        $updated = SolicitacoesCashOut::where('idTransaction', $this->idTransaction)
            ->update(array_merge($data, [
                'webhook_attempts' => \Illuminate\Support\Facades\DB::raw('webhook_attempts + 1'),
            ]));

        if ($updated === 0) {
            Solicitacoes::where('idTransaction', $this->idTransaction)
                ->update(array_merge($data, [
                    'webhook_attempts' => \Illuminate\Support\Facades\DB::raw('webhook_attempts + 1'),
                ]));
        }
    }

    /**
     * Remove chaves com valor null ou string vazia; recursivo para arrays aninhados (ex.: payer, beneficiary).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function filterEmpty(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $filtered = $this->filterEmpty($value);
                if ($filtered !== []) {
                    $out[$key] = $filtered;
                }
            } elseif ($value !== null && $value !== '') {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
