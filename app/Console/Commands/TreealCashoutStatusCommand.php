<?php

namespace App\Console\Commands;

use App\Services\Treeal\TreealPixAcquirerService;
use Illuminate\Console\Command;

class TreealCashoutStatusCommand extends Command
{
    protected $signature = 'treeal:cashout-status
                            {id? : idTransaction, endToEndId ou correlationId do saque}
                            {--e2e= : End-to-end (E...); use com ou sem id}';

    protected $description = 'Consulta status do Pix Out na API Contas TREEAL (BaaS)';

    public function handle(TreealPixAcquirerService $treeal): int
    {
        if (! $treeal->isActive()) {
            $this->error('Treeal não configurado (TREEAL_* + TREEAL_CONTAS_* + certificado mTLS).');

            return self::FAILURE;
        }

        $id = trim((string) ($this->argument('id') ?? ''));
        $e2e = trim((string) ($this->option('e2e') ?? ''));

        if ($id === '' && $e2e === '') {
            $this->error('Informe {id} ou --e2e=E...');

            return self::FAILURE;
        }

        $result = $treeal->getPayoutStatus($id, $e2e !== '' ? $e2e : null);

        if (! ($result['success'] ?? false)) {
            $this->warn($result['message'] ?? 'Falha na consulta.');

            return self::FAILURE;
        }

        $this->info(json_encode($result['raw'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line('status (mapeado): '.($result['status'] ?? '—'));

        return self::SUCCESS;
    }
}
