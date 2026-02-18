<?php

namespace App\Console\Commands;

use App\Models\Solicitacoes;
use App\Services\TreealService;
use App\Services\PaymentProcessingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fallback: verifica depósitos WAITING_FOR_APPROVAL e processa os que já foram pagos.
 *
 * Útil quando o webhook da Treeal não chega (URL não registrada, falha de rede, etc.).
 * Pode ser agendado via cron para rodar a cada minuto, ou executado manualmente.
 *
 * Uso:
 *   php artisan deposits:process-pending           → últimas 2h, todas as adquirentes
 *   php artisan deposits:process-pending --hours=6 → últimas 6h
 *   php artisan deposits:process-pending --dry-run → apenas lista, não processa
 */
class ProcessPendingDeposits extends Command
{
    protected $signature = 'deposits:process-pending
                            {--hours=2     : Janela de busca em horas (padrão 2)}
                            {--dry-run     : Apenas lista, não processa}
                            {--adquirente= : Filtrar por adquirente (ex: Treeal)}';

    protected $description = 'Processa depósitos pendentes consultando o status diretamente na adquirente (fallback de webhook)';

    public function handle(): int
    {
        $hours     = (int) $this->option('hours');
        $dryRun    = (bool) $this->option('dry-run');
        $adquirente = $this->option('adquirente');

        $since = Carbon::now()->subHours($hours);

        $this->info("🔍 Buscando depósitos WAITING_FOR_APPROVAL das últimas {$hours}h...");

        $query = Solicitacoes::where('status', 'WAITING_FOR_APPROVAL')
            ->where('date', '>=', $since)
            ->orderBy('date', 'asc');

        if ($adquirente) {
            $query->where('adquirente_ref', $adquirente);
        }

        $pendentes = $query->get();

        if ($pendentes->isEmpty()) {
            $this->info('✅ Nenhum depósito pendente encontrado.');
            return 0;
        }

        $this->info("📋 {$pendentes->count()} depósito(s) pendente(s) encontrado(s).");
        $this->newLine();

        $processados = 0;
        $erros = 0;

        foreach ($pendentes as $dep) {
            $this->line("  ID: {$dep->id} | TXID: {$dep->idTransaction} | Valor: R$ " .
                number_format($dep->amount, 2, ',', '.') . " | Adquirente: {$dep->adquirente_ref}");

            if ($dep->adquirente_ref === 'Treeal') {
                try {
                    $treeal = app(TreealService::class);

                    if (!$treeal->isActive()) {
                        $this->warn("  ⚠️  Treeal inativo, pulando.");
                        continue;
                    }

                    $statusResult = $treeal->getCobStatus($dep->idTransaction ?? $dep->externalreference);

                    $statusRemoto = strtoupper($statusResult['status'] ?? 'UNKNOWN');
                    $this->line("     Status na Treeal: {$statusRemoto}");

                    if (in_array($statusRemoto, ['CONCLUIDA', 'ATIVA', 'PAID', 'COMPLETED'])) {
                        if ($dryRun) {
                            $this->info("     [DRY-RUN] Seria processado.");
                        } else {
                            $paymentService = app(PaymentProcessingService::class);
                            $paymentService->processPaymentReceived($dep);
                            $this->info("     ✅ Processado! Saldo creditado.");
                            Log::info("ProcessPendingDeposits - Depósito processado via fallback", [
                                'id'    => $dep->id,
                                'txid'  => $dep->idTransaction,
                                'valor' => $dep->amount,
                            ]);
                            $processados++;
                        }
                    } else {
                        $this->line("     ⏳ Status ainda pendente na Treeal ({$statusRemoto}), aguardando.");
                    }
                } catch (\Exception $e) {
                    $this->error("     ❌ Erro: " . $e->getMessage());
                    Log::error("ProcessPendingDeposits - Erro ao processar", [
                        'id'    => $dep->id,
                        'error' => $e->getMessage(),
                    ]);
                    $erros++;
                }
            } else {
                $this->line("     ℹ️  Adquirente '{$dep->adquirente_ref}' não suportada neste comando.");
            }

            $this->newLine();
        }

        $this->info("─────────────────────────────────");
        $this->info("✅ Processados : {$processados}");
        if ($erros > 0) {
            $this->error("❌ Com erro    : {$erros}");
        }

        return 0;
    }
}
