<?php

namespace App\Console\Commands;

use App\Services\Simpay\SimpayPixAcquirerService;
use Illuminate\Console\Command;

class SimpayCashoutStatusCommand extends Command
{
    protected $signature = 'simpay:cashout-status
                            {id? : transaction_id Simpay do cash out (ex.: 31201108)}
                            {--e2e= : End-to-end (E3398...); use com ou sem id}';

    protected $description = 'Consulta status detalhado do PIX cash out na Simpay (incl. erro_descriptor em falhas)';

    public function handle(SimpayPixAcquirerService $simpay): int
    {
        if (! $simpay->isActive()) {
            $this->error('Simpay não configurado (SIMPAY_CLIENT_ID / SECRET / HMAC / conta origem).');

            return self::FAILURE;
        }

        $id = (string) ($this->argument('id') ?? '');
        $e2e = (string) ($this->option('e2e') ?? '');
        $e2e = trim($e2e);

        if ($id === '' && $e2e === '') {
            $this->error('Informe {id} ou --e2e=E3398...');

            return self::FAILURE;
        }

        $result = $simpay->getPayoutStatus($id, $e2e !== '' ? $e2e : null);

        if (! ($result['success'] ?? false)) {
            $this->warn($result['message'] ?? 'Falha');
            if (! empty($result['http_status'])) {
                $this->line('HTTP: '.$result['http_status']);
            }

            return self::FAILURE;
        }

        $this->info(json_encode($result['raw'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line('status (mapeado): '.($result['status'] ?? '—'));

        return self::SUCCESS;
    }
}
