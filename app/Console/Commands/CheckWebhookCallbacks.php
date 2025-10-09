<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Solicitacoes;
use Carbon\Carbon;

class CheckWebhookCallbacks extends Command
{
    protected $signature = 'webhook:check-callbacks {--limit=10}';
    protected $description = 'Verifica as últimas transações e seus callbacks configurados';

    public function handle()
    {
        $limit = $this->option('limit');
        
        $this->info("🔍 Verificando as últimas {$limit} transações e seus callbacks...\n");
        
        $transacoes = Solicitacoes::orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
            
        if ($transacoes->isEmpty()) {
            $this->warn('❌ Nenhuma transação encontrada.');
            return;
        }
        
        $this->table(
            ['ID', 'Transaction ID', 'Status', 'Callback URL', 'Criado em', 'Valor'],
            $transacoes->map(function ($transacao) {
                return [
                    $transacao->id,
                    $transacao->idTransaction,
                    $transacao->status,
                    $transacao->callback ?: 'N/A',
                    $transacao->created_at->format('d/m/Y H:i:s'),
                    'R$ ' . number_format($transacao->amount, 2, ',', '.')
                ];
            })
        );
        
        // Verificar quantas transações têm callback configurado
        $comCallback = $transacoes->where('callback', '!=', null)->where('callback', '!=', 'web')->count();
        $semCallback = $transacoes->count() - $comCallback;
        
        $this->info("\n📊 Estatísticas:");
        $this->line("✅ Com callback configurado: {$comCallback}");
        $this->line("❌ Sem callback configurado: {$semCallback}");
        
        // Mostrar exemplos de callbacks
        $callbacks = $transacoes->where('callback', '!=', null)
            ->where('callback', '!=', 'web')
            ->pluck('callback')
            ->unique()
            ->take(5);
            
        if ($callbacks->isNotEmpty()) {
            $this->info("\n🔗 Exemplos de callbacks encontrados:");
            foreach ($callbacks as $callback) {
                $this->line("  • {$callback}");
            }
        }
        
        $this->info("\n💡 Para testar um webhook específico, use:");
        $this->line("php test_webhook_cassino.php");
    }
}
