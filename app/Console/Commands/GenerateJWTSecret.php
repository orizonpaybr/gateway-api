<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateJWTSecret extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jwt:secret {--force : Sobrescrever JWT_SECRET existente}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gera uma nova chave secreta JWT e atualiza o arquivo .env';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $envPath = base_path('.env');
        
        if (!file_exists($envPath)) {
            $this->error('Arquivo .env não encontrado!');
            return 1;
        }

        $envContent = file_get_contents($envPath);
        
        // Gerar nova chave
        $newSecret = Str::random(64);
        
        // Verificar se já existe JWT_SECRET
        $hasExisting = preg_match('/^JWT_SECRET=.+$/m', $envContent);
        
        if ($hasExisting && !$this->option('force')) {
            $currentValue = '';
            if (preg_match('/^JWT_SECRET=(.*)$/m', $envContent, $matches)) {
                $currentValue = trim($matches[1]);
            }
            
            if ($currentValue && $currentValue !== 'SUA_CHAVE_JWT_AQUI') {
                if (!$this->confirm('JWT_SECRET já está definido. Deseja sobrescrever?')) {
                    $this->info('Operação cancelada.');
                    return 0;
                }
            }
        }
        
        // Atualizar ou adicionar JWT_SECRET
        if (preg_match('/^JWT_SECRET=.*$/m', $envContent)) {
            $envContent = preg_replace(
                '/^JWT_SECRET=.*$/m',
                'JWT_SECRET=' . $newSecret,
                $envContent
            );
        } else {
            $envContent .= "\n\nJWT_SECRET=" . $newSecret;
        }
        
        file_put_contents($envPath, $envContent);
        
        $this->info('JWT_SECRET gerado com sucesso!');
        $this->line('Nova chave: ' . substr($newSecret, 0, 10) . '...' . substr($newSecret, -10));
        $this->newLine();
        $this->warn('IMPORTANTE: Não compartilhe esta chave. Se você já tem tokens em uso, eles serão invalidados.');
        
        return 0;
    }
}
