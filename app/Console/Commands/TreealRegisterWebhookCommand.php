<?php

namespace App\Console\Commands;

use App\Services\Treeal\TreealWebhookRegistrationService;
use Illuminate\Console\Command;

class TreealRegisterWebhookCommand extends Command
{
    protected $signature = 'treeal:register-webhook
                            {--show : Exibe webhook cadastrado na Treeal}
                            {--delete : Remove webhook cadastrado na Treeal}';

    protected $description = 'Registra/consulta/remove webhook Pix CashIn na TREEAL (PUT /webhook/{chave})';

    public function handle(TreealWebhookRegistrationService $service): int
    {
        if ($this->option('delete')) {
            $result = $service->deletePixWebhook();
            if (! ($result['success'] ?? false)) {
                $this->error($result['message'] ?? 'Falha ao remover webhook.');

                return self::FAILURE;
            }

            $this->info('Webhook Pix TREEAL removido.');

            return self::SUCCESS;
        }

        if ($this->option('show')) {
            $result = $service->getPixWebhook();
            if (! ($result['success'] ?? false)) {
                $this->error($result['message'] ?? 'Falha ao consultar webhook.');

                return self::FAILURE;
            }

            $this->line(json_encode($result['raw'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $callbackBase = $service->resolveWebhookBaseUrl();
        $this->info('URL base do callback (Treeal enviará POST em {url}/pix): '.$callbackBase);

        $result = $service->configurePixWebhook();
        if (! ($result['success'] ?? false)) {
            $this->error($result['message'] ?? 'Falha ao registrar webhook.');

            return self::FAILURE;
        }

        $this->info('Webhook Pix TREEAL registrado.');
        $this->line(json_encode($result['raw'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
