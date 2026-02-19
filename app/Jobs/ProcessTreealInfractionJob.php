<?php

namespace App\Jobs;

use App\Models\Solicitacoes;
use App\Models\SolicitacoesCashOut;
use App\Models\WebhookLog;
use App\Services\PaymentProcessingService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processa webhook de Infração PIX da Treeal/ONZ em background.
 *
 * Payload (GET /api/v2/infractions): id, transactionId, status, type, creationDate,
 * lastModificationDate, reportedBy, reportDetails, analysisResult, analysisDetails, transactionAmount.
 * Resolve user_id pela transação (solicitacoes ou solicitacoes_cash_out) e persiste em pix_infracoes.
 */
class ProcessTreealInfractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    /** @param array<string, mixed> $data Payload do webhook (inner/data) */
    public function __construct(
        public array $data,
        public int $webhookLogId
    ) {}

    public function handle(PaymentProcessingService $paymentService): void
    {
        $jobStart = microtime(true);

        $transactionId = $this->data['transactionId'] ?? $this->data['transaction_id'] ?? null;
        if ($transactionId === null || $transactionId === '') {
            Log::warning('[TREEAL Infraction Job] Payload sem transactionId', [
                'data' => $this->data,
            ]);
            $this->failWebhookLog('transactionId não encontrado no payload');
            return;
        }

        $transactionId = (string) $transactionId;

        // Resolver user_id (username) pela transação — depósito ou saque
        $username = null;
        $endToEnd  = null;

        $deposito = Solicitacoes::where('idTransaction', $transactionId)
            ->orWhere('externalreference', $transactionId)
            ->first();

        if ($deposito) {
            $username = $deposito->user_id;
        } else {
            $saque = SolicitacoesCashOut::where('idTransaction', $transactionId)
                ->orWhere('externalreference', $transactionId)
                ->orWhere('end_to_end', $transactionId)
                ->first();
            if ($saque) {
                $username = $saque->user_id;
                $endToEnd = $saque->end_to_end;
            }
        }

        if (!$username) {
            Log::warning('[TREEAL Infraction Job] Transação não encontrada para resolver usuário', [
                'transaction_id' => $transactionId,
            ]);
            $this->failWebhookLog('Transação não encontrada (depósito/saque)');
            return;
        }

        if ($deposito && empty($endToEnd)) {
            $endToEnd = $deposito->end_to_end ?? null;
        }

        $statusOnz   = $this->data['status'] ?? 'OPEN';
        $typeOnz     = $this->data['type'] ?? 'FRAUD';
        $createdAt   = $this->data['creationDate'] ?? $this->data['lastModificationDate'] ?? null;
        $valor       = $this->getValorFromPayload();
        $reportDetails = $this->data['reportDetails'] ?? '';
        $analysisDetails = $this->data['analysisDetails'] ?? null;

        $dataCriacao = $createdAt ? Carbon::parse($createdAt) : now();
        $dataLimite  = $dataCriacao->copy()->addDays(7);

        $status = $this->mapStatus($statusOnz);
        $tipo   = $this->mapTipo($typeOnz);

        $detalhes = json_encode([
            'onz_id'            => $this->data['id'] ?? null,
            'reportedBy'        => $this->data['reportedBy'] ?? null,
            'analysisResult'    => $this->data['analysisResult'] ?? null,
            'analysisDetails'   => $analysisDetails,
            'lastModificationDate' => $this->data['lastModificationDate'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $attrs = [
                'user_id'        => $username,
                'transaction_id' => $transactionId,
            ];
            $values = [
                'status'       => $status,
                'tipo'         => $tipo,
                'descricao'    => $reportDetails,
                'valor'        => $valor,
                'end_to_end'   => $endToEnd,
                'data_criacao' => $dataCriacao,
                'data_limite'  => $dataLimite,
                'detalhes'     => $detalhes,
                'updated_at'   => now(),
            ];

            $exists = DB::table('pix_infracoes')
                ->where('user_id', $username)
                ->where('transaction_id', $transactionId)
                ->exists();

            if ($exists) {
                DB::table('pix_infracoes')
                    ->where('user_id', $username)
                    ->where('transaction_id', $transactionId)
                    ->update($values);
            } else {
                DB::table('pix_infracoes')->insert(array_merge($attrs, $values, [
                    'created_at' => $dataCriacao,
                ]));
            }

            $paymentService->invalidateInfractionCaches($username);

            $this->markWebhookProcessed();

            Log::info('[TREEAL Infraction Job] Infração processada', [
                'transaction_id' => $transactionId,
                'user_id'        => $username,
                'status'         => $status,
                'duration_ms'    => round((microtime(true) - $jobStart) * 1000, 2),
            ]);
        } catch (\Throwable $e) {
            Log::error('[TREEAL Infraction Job] Erro ao persistir infração', [
                'transaction_id' => $transactionId,
                'error'          => $e->getMessage(),
            ]);
            $this->failWebhookLog($e->getMessage());
            throw $e;
        }
    }

    private function getValorFromPayload(): float
    {
        $amount = $this->data['transactionAmount'] ?? null;
        if (is_array($amount)) {
            return (float) ($amount['value'] ?? $amount['amount'] ?? $amount['valor'] ?? 0);
        }
        return (float) ($this->data['valor'] ?? $this->data['amount'] ?? 0);
    }

    private function mapStatus(string $statusOnz): string
    {
        $map = [
            'OPEN'                 => 'PENDENTE',
            'ACKNOWLEDGED'         => 'EM_ANALISE',
            'WAITING_ADJUSTMENTS'  => 'EM_ANALISE',
            'DEFENDED'             => 'EM_ANALISE',
            'ANSWERED'             => 'EM_ANALISE',
            'CLOSED'               => 'RESOLVIDA',
            'CANCELLED'            => 'CANCELADA',
        ];
        return $map[strtoupper($statusOnz)] ?? 'PENDENTE';
    }

    private function mapTipo(string $typeOnz): string
    {
        $map = [
            'FRAUD'             => 'fraude',
            'REFUND_REQUEST'    => 'devolucao',
            'REFUND_CANCELLED'  => 'cancelada',
        ];
        return $map[strtoupper($typeOnz)] ?? 'pix';
    }

    private function markWebhookProcessed(): void
    {
        WebhookLog::where('id', $this->webhookLogId)->update(['status' => 'PROCESSED']);
    }

    private function failWebhookLog(string $error): void
    {
        WebhookLog::where('id', $this->webhookLogId)->update([
            'status' => 'FAILED',
            'error'  => $error,
        ]);
    }
}
