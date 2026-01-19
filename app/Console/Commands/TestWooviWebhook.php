<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Woovi;
use Illuminate\Support\Facades\Http;

class TestWooviWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woovi:test-webhook';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa o webhook da Woovi enviando uma requisição de teste';

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
