<?php

namespace App\Console\Commands;

use App\Services\Simpay\SimpayPixAcquirerService;
use Illuminate\Console\Command;

class SimpayCashoutStatusCommand extends Command
{
    protected $signature = 'simpay:cashout-status
                            {id : transaction_id Simpay do cash out (ex.: 31101108)}';

    protected $description = 'Consulta status detalhado do PIX cash out na Simpay (incl. erro_descriptor em falhas)';

    public function handle(SimpayPixAcquirerService $simpay): int
    {
        if (! $simpay->isActive()) {
            $this->error('Simpay não configurado (SIMPAY_CLIENT_ID / SECRET / HMAC / conta origem).');

            return self::FAILURE;
        }

        $id = (string) $this->argument('id');
        $result = $simpay->getPayoutStatus($id);

        if (! ($result['success'] ?? false)) {
            $this->warn($result['message'] ?? 'Falha');

            return self::FAILURE;
        }

        $this->info(json_encode($result['raw'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line('status (mapeado): '.($result['status'] ?? '—'));

        return self::SUCCESS;
    }
}
