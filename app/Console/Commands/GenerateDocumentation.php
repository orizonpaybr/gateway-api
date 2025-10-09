<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Helper;

class GenerateDocumentation extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'docs:generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate dynamic documentation with gateway name and app URL';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            $setting = Helper::getSetting();
            $appUrl = config('app.url');
            
            // Ler o arquivo doc.md
            $docPath = base_path('doc.md');
            if (!file_exists($docPath)) {
                $this->error('Arquivo doc.md não encontrado!');
                return 1;
            }
            
            $content = file_get_contents($docPath);
            
            // Substituir placeholders
            $replacements = [
                '{{GATEWAY_NAME}}' => $setting->gateway_name ?? 'HKPay',
                '{{APP_URL}}' => $appUrl,
            ];
            
            foreach ($replacements as $placeholder => $value) {
                $content = str_replace($placeholder, $value, $content);
            }
            
            // Salvar o arquivo processado
            $outputPath = public_path('docs/doc.md');
            if (!is_dir(dirname($outputPath))) {
                mkdir(dirname($outputPath), 0755, true);
            }
            
            file_put_contents($outputPath, $content);
            
            $this->info('Documentação gerada com sucesso!');
            $this->info('Gateway: ' . ($setting->gateway_name ?? 'HKPay'));
            $this->info('URL: ' . $appUrl);
            $this->info('Arquivo salvo em: ' . $outputPath);
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Erro ao gerar documentação: ' . $e->getMessage());
            return 1;
        }
    }
}