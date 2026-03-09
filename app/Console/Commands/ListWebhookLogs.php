<?php

namespace App\Console\Commands;

use App\Models\WebhookLog;
use Illuminate\Console\Command;

/**
 * Lista os últimos webhooks recebidos (para debug na VPS).
 *
 * Uso:
 *   php artisan webhooks:list
 *   php artisan webhooks:list --adquirente=heartpay
 *   php artisan webhooks:list --limit=20
 */
class ListWebhookLogs extends Command
{
    protected $signature = 'webhooks:list
                            {--adquirente= : Filtrar por adquirente (ex: heartpay)}
                            {--limit=15 : Quantidade de registros}';

    protected $description = 'Lista os últimos webhooks recebidos (debug)';

    public function handle(): int
    {
        $adquirente = $this->option('adquirente');
        $limit = (int) $this->option('limit');

        $query = WebhookLog::query()
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($adquirente) {
            $query->where('adquirente', $adquirente);
        }

        $logs = $query->get();

        if ($logs->isEmpty()) {
            $this->warn('Nenhum webhook encontrado.');
            if ($adquirente) {
                $this->line("Filtro: adquirente={$adquirente}");
            }
            return 0;
        }

        $this->info('Últimos webhooks recebidos:');
        $this->newLine();

        foreach ($logs as $log) {
            $payload = $log->payload ?? [];
            $txId = $log->transaction_id ?? ($payload['transactionId'] ?? $payload['txid'] ?? $payload['txId'] ?? '-');
            $endToEnd = $payload['endToEndId'] ?? $payload['end_to_end_id'] ?? '-';
            $status = $payload['status'] ?? $payload['paymentStatus'] ?? '-';

            $this->line(sprintf(
                '  [%s] %s | tx: %s | status: %s | log: %s',
                $log->created_at->format('Y-m-d H:i:s'),
                $log->adquirente,
                is_string($txId) ? substr($txId, 0, 30) : json_encode($txId),
                is_string($status) ? $status : json_encode($status),
                $log->status
            ));
            if ($endToEnd !== '-') {
                $this->line('    endToEnd: ' . (is_string($endToEnd) ? $endToEnd : json_encode($endToEnd)));
            }
            if ($log->error) {
                $this->line('    <error>erro: ' . substr($log->error, 0, 80) . '</error>');
            }
            $this->newLine();
        }

        $this->line('Para ver o payload completo de um registro, use:');
        $this->line('  php artisan tinker');
        $this->line('  App\\Models\\WebhookLog::find(ID)->payload');
        $this->newLine();
        $this->line('(dentro do tinker, digite só a linha acima sem o prompt >>>)');
        $this->newLine();
        $this->line('Para ver POSTs no endpoint do webhook (nginx):');
        $this->line('  grep "heartpay/webhook" /var/log/nginx/access.log | tail -30');

        return 0;
    }
}
