<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AsaasService;
use App\Models\Asaas;
use App\Models\Adquirente;

class TestAsaasIntegration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'asaas:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa a integração do Asaas no sistema';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->error('❌ Comando desativado: Asaas foi removido da aplicação.');
        $this->info('Apenas Pagar.me permanece ativo para processamento de cartão de crédito.');
        return 1;
    }
}
