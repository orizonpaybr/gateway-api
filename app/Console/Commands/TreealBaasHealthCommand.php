<?php

namespace App\Console\Commands;

use App\Models\SolicitacoesCashOut;
use App\Services\Treeal\TreealPixAcquirerService;
use App\Services\TreealContas\TreealContasPixOutService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TreealBaasHealthCommand extends Command
{
    protected $signature = 'treeal:baas-health
                            {--last=5 : Quantidade de saques FAILED recentes a listar}';

    protected $description = 'Diagnóstico operacional: config Treeal Contas (BaaS), falhas recentes e saldo insuficiente';

    public function handle(
        TreealPixAcquirerService $treeal,
        TreealContasPixOutService $contasPixOut,
    ): int {
        $this->info('=== Treeal BaaS (API Contas) — diagnóstico ===');
        $this->newLine();

        $cashInOk = $treeal->isActive();
        $cashOutOk = $contasPixOut->isConfigured();

        $this->table(
            ['Componente', 'Estado'],
            [
                ['Cash-in (QR Codes + mTLS treeal)', $cashInOk ? 'OK' : 'INCOMPLETO'],
                ['Cash-out (API Contas + TREEAL_CONTAS_*)', $cashOutOk ? 'OK' : 'INCOMPLETO'],
                ['Base URL Contas', (string) config('treeal_contas.base_url')],
            ]
        );

        if (! $cashOutOk) {
            $this->warn('Configure TREEAL_CONTAS_CLIENT_ID, TREEAL_CONTAS_CLIENT_SECRET e certificado mTLS antes de Pix Out.');

            return self::FAILURE;
        }

        $last = max(1, (int) $this->option('last'));
        $failed = SolicitacoesCashOut::query()
            ->where('executor_ordem', 'treeal')
            ->where('status', 'FAILED')
            ->orderByDesc('id')
            ->limit($last)
            ->get(['id', 'user_id', 'amount', 'status', 'idTransaction', 'end_to_end', 'date', 'valor_total_descontado', 'debito_saldo_principal', 'debito_saldo_afiliado']);

        $this->newLine();
        $this->info("Últimos {$last} saques Treeal com status FAILED:");

        if ($failed->isEmpty()) {
            $this->line('  (nenhum registro)');
        } else {
            $rows = $failed->map(fn ($w) => [
                $w->id,
                $w->user_id,
                number_format((float) $w->amount, 2, ',', '.'),
                $w->idTransaction,
                $w->date ? Carbon::parse((string) $w->date)->format('Y-m-d H:i') : '—',
                $this->debitLabel($w),
            ])->all();

            $this->table(
                ['ID', 'User', 'Valor', 'idTransaction', 'Data', 'Débito Coratri'],
                $rows
            );
        }

        $insufficientHits = $this->findInsufficientBalanceSignals();
        $this->newLine();
        $this->info('Sinais de "saldo insuficiente" (Treeal BaaS / logs):');
        if ($insufficientHits === []) {
            $this->line('  Nenhum hit recente em storage/logs/laravel*.log');
        } else {
            foreach ($insufficientHits as $line) {
                $this->line('  '.$line);
            }
        }

        $this->newLine();
        $this->comment('Ação operacional (fora do código):');
        $this->line('  1. Recarregar saldo na conta BaaS Treeal / ONZ (portal ou suporte comercial).');
        $this->line('  2. Confirmar ambiente: TREEAL_CONTAS_BASE_URL aponta para produção, não homologação.');
        $this->line('  3. Após recarga, testar Pix Out mínimo e consultar: php artisan treeal:cashout-status {idTransaction}');
        $this->line('  4. Verificar estorno Coratri: php artisan pixout:verify-refund {username}');

        return self::SUCCESS;
    }

    private function debitLabel(SolicitacoesCashOut $w): string
    {
        if ($w->debito_saldo_principal !== null || $w->debito_saldo_afiliado !== null) {
            return 'SIM (restituir se FAILED)';
        }

        return 'NÃO (falha antes do débito)';
    }

    /**
     * @return list<string>
     */
    private function findInsufficientBalanceSignals(): array
    {
        $patterns = [
            'Saldo insuficiente',
            'insufficient',
            'AM04',
            'OZ01',
            '[TREEAL][PAYOUT] Falha',
        ];

        $hits = [];
        $logDir = storage_path('logs');
        if (! is_dir($logDir)) {
            return [];
        }

        $files = File::glob($logDir.'/laravel*.log') ?: [];
        rsort($files);
        $files = array_slice($files, 0, 3);

        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            if (! is_array($lines)) {
                continue;
            }

            foreach (array_reverse($lines) as $line) {
                if (! is_string($line)) {
                    continue;
                }
                $lower = strtolower($line);
                foreach ($patterns as $pattern) {
                    if (stripos($line, $pattern) !== false && (str_contains($lower, 'treeal') || str_contains($lower, 'payout') || str_contains($lower, 'saldo'))) {
                        $hits[] = mb_substr(trim($line), 0, 220);
                        break;
                    }
                }
                if (count($hits) >= 8) {
                    break 2;
                }
            }
        }

        return array_values(array_unique($hits));
    }
}
