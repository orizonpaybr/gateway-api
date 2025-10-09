<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use App\Models\User;
use App\Models\SolicitacoesCashOut;

class GenerateSaqueReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'balance:saque-report {--user= : Filtrar por user_id} {--days=7 : Número de dias para o relatório} {--output= : Arquivo de saída (opcional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gera relatório detalhado de saques';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $userId = $this->option('user');
        $days = (int) $this->option('days');
        $outputFile = $this->option('output');
        
        $startDate = Carbon::now()->subDays($days);
        $endDate = Carbon::now();

        $this->info("Gerando relatório de saques dos últimos {$days} dias...");
        $this->info("Período: {$startDate->format('d/m/Y')} até {$endDate->format('d/m/Y')}");

        // Buscar saques no período
        $query = SolicitacoesCashOut::whereBetween('created_at', [$startDate, $endDate]);
        
        if ($userId) {
            $query->where('user_id', $userId);
        }

        $saques = $query->orderBy('created_at', 'desc')->get();

        if ($saques->isEmpty()) {
            $this->info('Nenhum saque encontrado no período especificado.');
            return 0;
        }

        // Estatísticas gerais
        $totalSaques = $saques->count();
        $totalValor = $saques->sum('amount');
        $totalLiquido = $saques->sum('cash_out_liquido');
        $lucroPlataforma = $totalValor - $totalLiquido;

        $statusCounts = $saques->groupBy('status')->map->count();
        $adquirenteCounts = $saques->groupBy('adquirente')->map->count();

        // Gerar relatório
        $report = [];
        $report[] = "=== RELATÓRIO DE SAQUES ===";
        $report[] = "Período: {$startDate->format('d/m/Y H:i')} até {$endDate->format('d/m/Y H:i')}";
        $report[] = "Total de saques: {$totalSaques}";
        $report[] = "Valor total bruto: R$ " . number_format($totalValor, 2, ',', '.');
        $report[] = "Valor total líquido: R$ " . number_format($totalLiquido, 2, ',', '.');
        $report[] = "Lucro da plataforma: R$ " . number_format($lucroPlataforma, 2, ',', '.');
        $report[] = "";

        // Status dos saques
        $report[] = "=== STATUS DOS SAQUES ===";
        foreach ($statusCounts as $status => $count) {
            $percentage = ($count / $totalSaques) * 100;
            $report[] = "{$status}: {$count} ({$percentage}%)";
        }
        $report[] = "";

        // Por adquirente
        $report[] = "=== SAQUES POR ADQUIRENTE ===";
        foreach ($adquirenteCounts as $adquirente => $count) {
            $percentage = ($count / $totalSaques) * 100;
            $report[] = "{$adquirente}: {$count} ({$percentage}%)";
        }
        $report[] = "";

        // Detalhes dos saques
        $report[] = "=== DETALHES DOS SAQUES ===";
        $report[] = sprintf("%-5s %-20s %-15s %-10s %-12s %-15s %-20s %-10s", 
            'ID', 'Usuário', 'Valor Bruto', 'Valor Liq.', 'Taxa', 'Adquirente', 'Status', 'Data');
        $report[] = str_repeat('-', 120);

        foreach ($saques as $saque) {
            $user = User::where('user_id', $saque->user_id)->first();
            $userName = $user ? substr($user->name, 0, 20) : 'N/A';
            $taxa = $saque->amount - $saque->cash_out_liquido;
            
            $report[] = sprintf("%-5s %-20s %-15s %-10s %-12s %-15s %-20s %-10s",
                $saque->id,
                $userName,
                'R$ ' . number_format($saque->amount, 2, ',', '.'),
                'R$ ' . number_format($saque->cash_out_liquido, 2, ',', '.'),
                'R$ ' . number_format($taxa, 2, ',', '.'),
                $saque->adquirente ?? 'N/A',
                $saque->status,
                $saque->created_at->format('d/m/Y H:i')
            );
        }

        $reportContent = implode("\n", $report);

        // Exibir no console
        $this->line($reportContent);

        // Salvar em arquivo se especificado
        if ($outputFile) {
            file_put_contents($outputFile, $reportContent);
            $this->info("Relatório salvo em: {$outputFile}");
        }

        return 0;
    }
}

