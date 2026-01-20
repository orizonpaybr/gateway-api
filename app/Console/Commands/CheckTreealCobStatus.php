<?php

namespace App\Console\Commands;

use App\Services\TreealService;
use App\Models\Treeal;
use Illuminate\Console\Command;

class CheckTreealCobStatus extends Command
{
    protected $signature = 'treeal:check-cob-status {txid}';
    protected $description = 'Consulta status de uma cobrança PIX na Treeal/ONZ';

    public function handle()
    {
        $txid = $this->argument('txid');

        $this->info("=== Consultando Status da Cobrança ===");
        $this->newLine();
        $this->line("TXID: {$txid}");
        $this->newLine();

        try {
            $service = app(TreealService::class);

            if (!$service->isActive()) {
                $this->error('❌ Treeal não está configurado ou ativo');
                return 1;
            }

            $result = $service->getCobStatus($txid);

            if (!$result['success']) {
                $this->error('❌ Cobrança não encontrada');
                $this->line("Status: {$result['status']}");
                return 1;
            }

            $status = $result['status'];
            $data = $result['data'] ?? [];

            $this->info("✅ Status da Cobrança:");
            $this->line("   Status: {$status}");
            
            if (isset($data['valor']['original'])) {
                $this->line("   Valor: R$ " . number_format((float)$data['valor']['original'], 2, ',', '.'));
            }

            if (isset($data['chave'])) {
                $this->line("   Chave PIX: {$data['chave']}");
            }

            if (isset($data['calendario']['criacao'])) {
                $this->line("   Criada em: {$data['calendario']['criacao']}");
            }

            if (isset($data['calendario']['expiracao'])) {
                $this->line("   Expira em: {$data['calendario']['expiracao']} segundos");
            }

            $this->newLine();

            // Sugerir próximo passo
            if (in_array(strtoupper($status), ['CONCLUIDA', 'ATIVA', 'PAID', 'COMPLETED'])) {
                $this->info("💡 Próximo passo:");
                $this->line("   Simule o webhook de pagamento:");
                $this->line("   POST /treeal/webhook");
                $this->line("   {");
                $this->line("     \"txid\": \"{$txid}\",");
                $this->line("     \"status\": \"CONCLUIDA\",");
                $this->line("     \"endToEndId\": \"E12345678202601201234567890123456\"");
                $this->line("   }");
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Erro: ' . $e->getMessage());
            return 1;
        }
    }
}
