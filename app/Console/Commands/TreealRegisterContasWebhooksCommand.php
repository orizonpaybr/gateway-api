<?php

namespace App\Console\Commands;

use App\Services\TreealContas\TreealContasWebhookRegistrationService;
use Illuminate\Console\Command;

class TreealRegisterContasWebhooksCommand extends Command
{
    protected $signature = 'treeal:register-contas-webhooks
                            {--show : Lista webhooks cadastrados na Treeal Contas}
                            {--type= : Registra apenas um tipo (TRANSFER, RECEIVE, REFUND, CASHOUT)}';

    protected $description = 'Registra webhooks Contas TREEAL (TRANSFER, RECEIVE, REFUND, CASHOUT)';

    public function handle(TreealContasWebhookRegistrationService $service): int
    {
        if ($this->option('show')) {
            $result = $service->listWebhooks();
            if (! ($result['success'] ?? false)) {
                $this->error($result['message'] ?? 'Falha ao listar webhooks.');

                return self::FAILURE;
            }

            $this->line(json_encode($result['raw'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $uri = $service->resolveWebhookUri();
        $this->info('URI de callback Contas: '.$uri);

        $type = trim((string) $this->option('type'));
        if ($type !== '') {
            $result = $service->registerWebhook($type);
            if (! ($result['success'] ?? false)) {
                $this->error($result['message'] ?? 'Falha ao registrar webhook.');

                return self::FAILURE;
            }

            $this->info('Webhook '.$type.' registrado.');
            $this->line(json_encode($result['raw'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $result = $service->registerAll();
        foreach ($result['registered'] as $registeredType) {
            $this->info('Registrado: '.$registeredType);
        }
        foreach ($result['failed'] as $failedType => $message) {
            $this->error($failedType.': '.$message);
        }

        return ($result['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
