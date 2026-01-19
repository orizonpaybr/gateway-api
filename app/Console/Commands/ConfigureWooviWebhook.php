<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\WooviService;
use App\Models\Woovi;
use Illuminate\Support\Str;

class ConfigureWooviWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woovi:configure-webhook {--url=} {--secret=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Configura o webhook da Woovi com URL e secret de autorização';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->error('❌ Comando desativado: Woovi foi removido da aplicação.');
        $this->info('Apenas Pagar.me permanece ativo para processamento de cartão de crédito.');
        return 1;
    }
}
