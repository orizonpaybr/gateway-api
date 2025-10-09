<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Traits\SplitTrait;
use App\Models\SplitPayment;

class ProcessSplits extends Command
{
    protected $signature = 'splits:process {--limit=50} {--retry-failed}';
    protected $description = 'Processa splits pendentes em lote';

    public function handle()
    {
        $this->info('🔄 Iniciando processamento de splits...');
        
        $limit = $this->option('limit');
        $retryFailed = $this->option('retry-failed');
        
        // Contar splits pendentes
        $pendingCount = SplitPayment::pending()->count();
        $this->info("📊 Splits pendentes encontrados: {$pendingCount}");
        
        if ($pendingCount === 0) {
            $this->info('✅ Nenhum split pendente para processar.');
            return;
        }
        
        // Processar splits pendentes
        $this->info("🚀 Processando até {$limit} splits...");
        $results = SplitTrait::processPendingSplits();
        
        // Estatísticas
        $completed = collect($results)->where('result.status', 'completed')->count();
        $failed = collect($results)->where('result.status', 'failed')->count();
        
        $this->info("✅ Splits processados com sucesso: {$completed}");
        $this->info("❌ Splits que falharam: {$failed}");
        
        // Reprocessar falhados se solicitado
        if ($retryFailed && $failed > 0) {
            $this->info('🔄 Tentando reprocessar splits falhados...');
            
            $failedSplits = SplitPayment::failed()->limit($limit)->get();
            $retryCount = 0;
            
            foreach ($failedSplits as $split) {
                $split->update(['split_status' => SplitPayment::STATUS_PENDING]);
                $retryCount++;
            }
            
            $this->info("🔄 {$retryCount} splits falhados marcados para reprocessamento.");
        }
        
        // Mostrar resumo
        $this->table(
            ['Status', 'Quantidade'],
            [
                ['Pendentes', SplitPayment::pending()->count()],
                ['Processando', SplitPayment::where('split_status', 'processing')->count()],
                ['Concluídos', SplitPayment::completed()->count()],
                ['Falhados', SplitPayment::failed()->count()],
                ['Cancelados', SplitPayment::where('split_status', 'cancelled')->count()],
            ]
        );
        
        $this->info('🎉 Processamento de splits concluído!');
    }
}
