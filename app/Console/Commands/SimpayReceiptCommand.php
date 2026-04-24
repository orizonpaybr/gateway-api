<?php

namespace App\Console\Commands;

use App\Services\Simpay\SimpayPixAcquirerService;
use Illuminate\Console\Command;

class SimpayReceiptCommand extends Command
{
    protected $signature = 'simpay:receipt
                            {id? : transaction_id Simpay (ex.: 30901108)}
                            {--uuid= : EndToEnd / operationUuid}
                            {--lang=portuguese : portuguese|english|chinese}';

    protected $description = 'Consulta comprovante/detalhes de transação PIX cash out na API Simpay (debug suporte)';

    public function handle(SimpayPixAcquirerService $simpay): int
    {
        if (! $simpay->isActive()) {
            $this->error('Simpay não configurado (SIMPAY_CLIENT_ID / SECRET / HMAC / conta origem).');

            return self::FAILURE;
        }

        $id = $this->argument('id');
        $uuid = $this->option('uuid') ?: null;
        $lang = (string) $this->option('lang');

        if (($id === null || $id === '') && ($uuid === null || trim($uuid) === '')) {
            $this->error('Informe id (argumento) ou --uuid=.');

            return self::FAILURE;
        }

        $result = $simpay->getReceiptTransaction($id, $uuid, $lang);

        if (! ($result['success'] ?? false)) {
            $this->warn($result['message'] ?? 'Falha');
            if (! empty($result['http_status'])) {
                $this->line('HTTP: '.$result['http_status']);
            }
            if (! empty($result['raw'])) {
                $this->line(json_encode($result['raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            if (($result['http_status'] ?? null) === 404 && ($id !== null && $id !== '')) {
                $this->comment('Dica: cash out com falha costuma não ter comprovante em arquivo. Tente: php artisan simpay:cashout-status '.$id);
            }

            return self::FAILURE;
        }

        $this->info(json_encode($result['data'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
