<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Preenche o campo valor_sacado de todos os usuários com base nos saques
 * realmente pagos (COMPLETED, PAID_OUT) na tabela solicitacoes_cash_out.
 *
 * Uso:
 *   php artisan users:backfill-valor-sacado --dry-run   (apenas mostra, não altera)
 *   php artisan users:backfill-valor-sacado              (executa de fato)
 */
class BackfillValorSacado extends Command
{
    protected $signature = 'users:backfill-valor-sacado
                            {--dry-run : Apenas lista os valores que seriam atualizados, sem alterar o banco}';

    protected $description = 'Preenche valor_sacado dos usuários com base nos saques pagos (COMPLETED/PAID_OUT)';

    private const PAID_STATUSES = ['COMPLETED', 'PAID_OUT'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? '=== DRY RUN (nenhum dado será alterado) ===' : '=== Executando backfill ===');
        $this->newLine();

        $totals = DB::table('solicitacoes_cash_out')
            ->select('user_id', DB::raw('COALESCE(SUM(amount), 0) as total_sacado'), DB::raw('COUNT(*) as qtd_saques'))
            ->whereIn('status', self::PAID_STATUSES)
            ->groupBy('user_id')
            ->get();

        if ($totals->isEmpty()) {
            $this->warn('Nenhum saque pago encontrado na base.');
            return self::SUCCESS;
        }

        $this->info("Encontrados {$totals->count()} usuários com saques pagos.");
        $this->newLine();

        $updated = 0;
        $skipped = 0;

        $headers = ['User ID', 'Username', 'Valor Atual', 'Valor Correto', 'Saques Pagos', 'Ação'];
        $rows = [];

        foreach ($totals as $row) {
            $user = User::where('user_id', $row->user_id)->first();

            if (!$user) {
                $this->warn("  Usuário {$row->user_id} não encontrado, pulando.");
                $skipped++;
                continue;
            }

            $valorAtual = (float) ($user->valor_sacado ?? 0);
            $valorCorreto = round((float) $row->total_sacado, 2);

            if (abs($valorAtual - $valorCorreto) < 0.01) {
                $rows[] = [
                    $row->user_id,
                    $user->username,
                    'R$ ' . number_format($valorAtual, 2, ',', '.'),
                    'R$ ' . number_format($valorCorreto, 2, ',', '.'),
                    $row->qtd_saques,
                    'OK (já correto)',
                ];
                $skipped++;
                continue;
            }

            $rows[] = [
                $row->user_id,
                $user->username,
                'R$ ' . number_format($valorAtual, 2, ',', '.'),
                'R$ ' . number_format($valorCorreto, 2, ',', '.'),
                $row->qtd_saques,
                $dryRun ? 'ATUALIZARIA' : 'ATUALIZADO',
            ];

            if (!$dryRun) {
                $user->update(['valor_sacado' => $valorCorreto]);

                Log::info('BackfillValorSacado: valor_sacado atualizado', [
                    'user_id' => $row->user_id,
                    'username' => $user->username,
                    'valor_anterior' => $valorAtual,
                    'valor_novo' => $valorCorreto,
                    'qtd_saques_pagos' => $row->qtd_saques,
                ]);
            }

            $updated++;
        }

        $this->table($headers, $rows);
        $this->newLine();

        if ($dryRun) {
            $this->info("Resumo (dry-run): {$updated} seriam atualizados, {$skipped} já estão corretos ou não encontrados.");
            $this->info('Execute sem --dry-run para aplicar as alterações.');
        } else {
            $this->info("Resumo: {$updated} atualizados, {$skipped} já estavam corretos ou não encontrados.");
        }

        return self::SUCCESS;
    }
}
