<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class UpdateTreealCredentials extends Command
{
    protected $signature = 'treeal:update-credentials';
    protected $description = 'Mostra informações sobre como configurar credenciais Treeal/ONZ';

    public function handle()
    {
        $this->warn('⚠️  Este comando foi descontinuado.');
        $this->newLine();
        $this->info('As credenciais sensíveis agora devem ser configuradas no arquivo .env:');
        $this->newLine();
        $this->line('  TREEAL_CERTIFICATE_PATH=PIX-HMG-CLIENTE.pfx');
        $this->line('  TREEAL_CERTIFICATE_PASSWORD=sua_senha');
        $this->line('  TREEAL_ACCOUNTS_CLIENT_ID=seu_client_id');
        $this->line('  TREEAL_ACCOUNTS_CLIENT_SECRET=seu_client_secret');
        $this->line('  TREEAL_QRCODES_CLIENT_ID=seu_qrcodes_client_id');
        $this->line('  TREEAL_QRCODES_CLIENT_SECRET=seu_qrcodes_client_secret');
        $this->line('  TREEAL_PIX_KEY_SECONDARY=sua_chave_pix');
        $this->newLine();
        $this->info('Após atualizar o .env, execute: php artisan config:clear');
        $this->info('Para testar a conexão: php artisan treeal:test-connection');
        
        return 0;
    }
}
