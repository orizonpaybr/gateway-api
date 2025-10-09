<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SecurityMonitor extends Command
{
    protected $signature = 'security:monitor';
    protected $description = 'Monitora arquivos suspeitos e possíveis backdoors';

    public function handle()
    {
        $this->info('Iniciando monitoramento de segurança...');
        
        $suspiciousFiles = [];
        $uploadDirs = [
            public_path('uploads'),
            storage_path('app/public'),
        ];

        foreach ($uploadDirs as $dir) {
            if (!File::exists($dir)) continue;
            
            $files = File::allFiles($dir);
            foreach ($files as $file) {
                $extension = strtolower($file->getExtension());
                $filename = $file->getFilename();
                
                // Verificar extensões perigosas
                $dangerousExtensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'pl', 'py', 'jsp', 'asp', 'sh', 'cgi'];
                if (in_array($extension, $dangerousExtensions)) {
                    $suspiciousFiles[] = $file->getPathname();
                }
                
                // Verificar nomes suspeitos
                $suspiciousPatterns = ['/shell/i', '/backdoor/i', '/hack/i', '/exploit/i'];
                foreach ($suspiciousPatterns as $pattern) {
                    if (preg_match($pattern, $filename)) {
                        $suspiciousFiles[] = $file->getPathname();
                        break;
                    }
                }
            }
        }

        if (!empty($suspiciousFiles)) {
            $this->error('⚠️  ARQUIVOS SUSPEITOS ENCONTRADOS:');
            foreach ($suspiciousFiles as $file) {
                $this->line("- $file");
            }
            
            Log::warning('Arquivos suspeitos detectados', ['files' => $suspiciousFiles]);
            return 1;
        }

        $this->info('✅ Nenhum arquivo suspeito encontrado.');
        return 0;
    }
}
