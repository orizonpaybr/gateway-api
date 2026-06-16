<?php

namespace App\Console\Commands;

use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class VerifyPixOutRefundCommand extends Command
{
    protected $signature = 'pixout:verify-refund
                            {username : username ou user_id do cliente}
                            {--id= : ID específico em solicitacoes_cash_out}
                            {--last=5 : Quantidade de saques FAILED a analisar}';

    protected $description = 'Verifica se saques FAILED restituíram saldo Coratri (débito + log WITHDRAWAL_REFUND)';

    public function handle(): int
    {
        $username = trim((string) $this->argument('username'));
        $user = User::query()
            ->where('username', $username)
            ->orWhere('user_id', $username)
            ->first();

        if (! $user) {
            $this->error("Usuário não encontrado: {$username}");

            return self::FAILURE;
        }

        $this->info("Usuário: {$user->username} (id={$user->id})");
        $this->line(sprintf(
            'Saldo actual: principal R$ %s | afiliado R$ %s | total R$ %s',
            number_format((float) ($user->saldo ?? 0), 2, ',', '.'),
            number_format((float) ($user->saldo_afiliado ?? 0), 2, ',', '.'),
            number_format((float) ($user->saldo ?? 0) + (float) ($user->saldo_afiliado ?? 0), 2, ',', '.'),
        ));
        $this->newLine();

        $cashOutId = $this->option('id');
        if ($cashOutId !== null && $cashOutId !== '') {
            $withdrawals = SolicitacoesCashOut::query()
                ->where('id', (int) $cashOutId)
                ->where(function ($q) use ($user) {
                    $q->where('user_id', $user->username)
                        ->orWhere('user_id', $user->user_id);
                })
                ->get();
        } else {
            $last = max(1, (int) $this->option('last'));
            $withdrawals = SolicitacoesCashOut::query()
                ->where(function ($q) use ($user) {
                    $q->where('user_id', $user->username)
                        ->orWhere('user_id', $user->user_id);
                })
                ->where('status', 'FAILED')
                ->orderByDesc('id')
                ->limit($last)
                ->get();
        }

        if ($withdrawals->isEmpty()) {
            $this->warn('Nenhum saque FAILED encontrado para este filtro.');

            return self::SUCCESS;
        }

        $refundLogs = $this->loadWithdrawalRefundLogs();

        foreach ($withdrawals as $w) {
            $this->analyzeWithdrawal($w, $refundLogs);
            $this->newLine();
        }

        $this->comment('SQL útil (substituir IDs):');
        $this->line('  SELECT id, status, amount, valor_total_descontado, debito_saldo_principal, debito_saldo_afiliado, date FROM solicitacoes_cash_out WHERE user_id = \''.$user->username.'\' ORDER BY id DESC LIMIT 10;');
        $this->line('  SELECT saldo, saldo_afiliado FROM users WHERE username = \''.$user->username.'\';');
        $this->line('  grep WITHDRAWAL_REFUND storage/logs/laravel*.log | tail -20');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $refundLogs  keyed by cash_out_id
     */
    private function analyzeWithdrawal(SolicitacoesCashOut $w, array $refundLogs): void
    {
        $hadDebit = $w->debito_saldo_principal !== null || $w->debito_saldo_afiliado !== null;
        $valorDebitado = $w->valor_total_descontado !== null && (float) $w->valor_total_descontado > 0
            ? (float) $w->valor_total_descontado
            : (float) $w->amount + (float) ($w->taxa_cash_out ?? 0);

        $this->info("Saque #{$w->id} | {$w->status} | R$ ".number_format((float) $w->amount, 2, ',', '.')." | {$w->executor_ordem} | {$w->idTransaction}");

        if (! $hadDebit) {
            $this->line('<fg=yellow>  Débito Coratri: NÃO</> — falha antes do decremento (ex.: Treeal recusou na API). Saldo do cliente não deveria ter sido reduzido.');
            $this->line('  Resultado esperado: OK (nada a restituir).');

            return;
        }

        $this->line(sprintf(
            '  Débito Coratri: SIM — total R$ %s (principal R$ %s, afiliado R$ %s)',
            number_format($valorDebitado, 2, ',', '.'),
            number_format((float) ($w->debito_saldo_principal ?? 0), 2, ',', '.'),
            number_format((float) ($w->debito_saldo_afiliado ?? 0), 2, ',', '.'),
        ));

        $logEntry = $refundLogs[$w->id] ?? null;
        if ($logEntry !== null) {
            $this->line('<fg=green>  Estorno: SIM</> — log [WITHDRAWAL_REFUND] encontrado.');
            if (isset($logEntry['valor_devolvido'])) {
                $this->line('    valor_devolvido: R$ '.number_format((float) $logEntry['valor_devolvido'], 2, ',', '.'));
            }

            return;
        }

        if ($w->status === 'FAILED') {
            $this->line('<fg=red>  Estorno: NÃO CONFIRMADO</> — débito registado mas sem log [WITHDRAWAL_REFUND] para cash_out_id='.$w->id);
            $this->line('  Investigar: CashOutOutcomeApplier / webhook Treeal CASHOUT REJECTED.');
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadWithdrawalRefundLogs(): array
    {
        $map = [];
        $logDir = storage_path('logs');
        $files = is_dir($logDir) ? (File::glob($logDir.'/laravel*.log') ?: []) : [];

        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            if (! is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                if (! is_string($line) || ! str_contains($line, '[WITHDRAWAL_REFUND] Saldo restituído')) {
                    continue;
                }

                if (preg_match('/"cash_out_id":(\d+)/', $line, $m)) {
                    $id = (int) $m[1];
                    $entry = ['raw' => mb_substr($line, 0, 300)];
                    if (preg_match('/"valor_devolvido":([\d.]+)/', $line, $v)) {
                        $entry['valor_devolvido'] = (float) $v[1];
                    }
                    $map[$id] = $entry;
                }
            }
        }

        return $map;
    }
}
