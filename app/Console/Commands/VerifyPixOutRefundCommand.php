<?php

namespace App\Console\Commands;

use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class VerifyPixOutRefundCommand extends Command
{
    protected $signature = 'pixout:verify-refund
                            {username : username ou user_id do cliente}
                            {--id= : ID específico em solicitacoes_cash_out}
                            {--last=5 : Quantidade de saques FAILED a analisar}
                            {--all-users : Analisa saques FAILED de todos os usuários (ignora username)}';

    protected $description = 'Verifica se saques FAILED restituíram saldo Coratri (débito + log WITHDRAWAL_REFUND)';

    /** Número de horas para considerar um registo "recente" (log ainda não rotacionado). */
    private const RECENT_HOURS = 48;

    public function handle(): int
    {
        if ($this->option('all-users')) {
            return $this->handleAllUsers();
        }

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
                ->whereNotNull('debito_saldo_principal')
                ->orderByDesc('id')
                ->limit($last)
                ->get();
        }

        if ($withdrawals->isEmpty()) {
            $this->warn('Nenhum saque FAILED com débito registado encontrado para este filtro.');

            return self::SUCCESS;
        }

        $refundLogs = $this->loadWithdrawalRefundLogs();
        $this->info('Nota: log pesquisado em '.count($refundLogs).' entradas. Registos com mais de '.self::RECENT_HOURS.'h podem ter o log rotacionado — NÃO CONFIRMADO não implica necessariamente bug nesses casos.');
        $this->newLine();

        $totalDebitSemEstorno = 0.0;
        $alertCount = 0;

        foreach ($withdrawals as $w) {
            ['alert' => $alert, 'debit' => $debit] = $this->analyzeWithdrawal($w, $refundLogs);
            if ($alert) {
                $alertCount++;
                $totalDebitSemEstorno += $debit;
            }
            $this->newLine();
        }

        if ($alertCount > 0) {
            $this->warn(sprintf(
                '%d saque(s) RECENTE(S) com débito sem log de estorno — total em risco: R$ %s',
                $alertCount,
                number_format($totalDebitSemEstorno, 2, ',', '.')
            ));
            $this->line('  → Confirmar manualmente ou usar: php artisan pixout:manual-refund {id} --dry-run');
        }

        $this->newLine();
        $this->comment('SQL de verificação directa:');
        $this->line("  SELECT id, status, amount, valor_total_descontado, debito_saldo_principal, debito_saldo_afiliado, date FROM solicitacoes_cash_out WHERE user_id = '{$user->username}' ORDER BY id DESC LIMIT 10;");
        $this->line("  SELECT saldo, saldo_afiliado FROM users WHERE username = '{$user->username}';");
        $this->line('  grep WITHDRAWAL_REFUND storage/logs/laravel*.log | tail -30');

        return self::SUCCESS;
    }

    private function handleAllUsers(): int
    {
        $last = max(1, (int) $this->option('last'));
        $withdrawals = SolicitacoesCashOut::query()
            ->where('status', 'FAILED')
            ->whereNotNull('debito_saldo_principal')
            ->orderByDesc('id')
            ->limit($last * 5)
            ->get();

        if ($withdrawals->isEmpty()) {
            $this->warn('Nenhum saque FAILED com débito encontrado.');

            return self::SUCCESS;
        }

        $refundLogs = $this->loadWithdrawalRefundLogs();
        $this->info('Análise global — últimos '.($last * 5).' saques FAILED com débito:');
        $this->newLine();

        $alerts = [];
        foreach ($withdrawals as $w) {
            ['alert' => $alert, 'debit' => $debit] = $this->analyzeWithdrawal($w, $refundLogs);
            if ($alert) {
                $alerts[] = ['id' => $w->id, 'user' => $w->user_id, 'valor' => $debit];
            }
            $this->newLine();
        }

        if (! empty($alerts)) {
            $this->warn('Saques recentes SEM estorno confirmado:');
            $this->table(['ID', 'User', 'Valor em risco'], array_map(
                fn ($a) => [$a['id'], $a['user'], 'R$ '.number_format($a['valor'], 2, ',', '.')],
                $alerts
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $refundLogs
     * @return array{alert: bool, debit: float}
     */
    private function analyzeWithdrawal(SolicitacoesCashOut $w, array $refundLogs): array
    {
        $date = $w->date ? Carbon::parse((string) $w->date) : null;
        $isRecent = $date === null || $date->diffInHours(now()) <= self::RECENT_HOURS;
        $ageLabel = $date ? $date->format('Y-m-d H:i') : '(data desconhecida)';

        $hadDebit = $w->debito_saldo_principal !== null || $w->debito_saldo_afiliado !== null;
        $valorDebitado = $w->valor_total_descontado !== null && (float) $w->valor_total_descontado > 0
            ? (float) $w->valor_total_descontado
            : (float) $w->amount + (float) ($w->taxa_cash_out ?? 0);

        $this->line(sprintf(
            '<fg=cyan>Saque #%d</> | %s | R$ %s | <fg=yellow>%s</> | %s | %s',
            $w->id,
            $w->status,
            number_format((float) $w->amount, 2, ',', '.'),
            $w->executor_ordem ?? '—',
            $w->idTransaction,
            $ageLabel,
        ));

        if (! $hadDebit) {
            $this->line('  <fg=green>Débito Coratri: NÃO</> — falha antes do decremento. Saldo não deveria ter caído.');

            return ['alert' => false, 'debit' => 0.0];
        }

        $this->line(sprintf(
            '  Débito Coratri: R$ %s (principal R$ %s, afiliado R$ %s)',
            number_format($valorDebitado, 2, ',', '.'),
            number_format((float) ($w->debito_saldo_principal ?? 0), 2, ',', '.'),
            number_format((float) ($w->debito_saldo_afiliado ?? 0), 2, ',', '.'),
        ));

        $logEntry = $refundLogs[$w->id] ?? null;
        if ($logEntry !== null) {
            $valorLog = isset($logEntry['valor_devolvido'])
                ? ' (R$ '.number_format((float) $logEntry['valor_devolvido'], 2, ',', '.').')'
                : '';
            $this->line("<fg=green>  Estorno: CONFIRMADO</> — log [WITHDRAWAL_REFUND] encontrado{$valorLog}.");

            return ['alert' => false, 'debit' => 0.0];
        }

        if ($isRecent) {
            $this->line('<fg=red>  Estorno: NÃO CONFIRMADO (registo RECENTE)</> — débito registado mas sem log de estorno. ALERTA!');
            $this->line('  → Verificar: grep WITHDRAWAL_REFUND storage/logs/laravel*.log | grep "cash_out_id":'.$w->id);
            $this->line('  → Corrigir:  php artisan pixout:manual-refund '.$w->id.' --dry-run');
        } else {
            $this->line('<fg=yellow>  Estorno: NÃO CONFIRMADO (registo antigo — log provavelmente rotacionado)</>.');
            $this->line('  → Verificar via DB se saldo foi credenciado antes de actuar.');
        }

        return ['alert' => $isRecent, 'debit' => $valorDebitado];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadWithdrawalRefundLogs(): array
    {
        $map = [];
        $logDir = storage_path('logs');
        if (! is_dir($logDir)) {
            return [];
        }

        // Todos os ficheiros de log Laravel (sem limite de 3)
        $files = File::glob($logDir.'/laravel*.log') ?: [];
        rsort($files);

        // Também verificar o log de análise de saques
        $extraLog = $logDir.'/analisarsaque.log';
        if (is_file($extraLog)) {
            array_unshift($files, $extraLog);
        }

        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            if (! is_array($lines)) {
                continue;
            }

            foreach (array_reverse($lines) as $line) {
                if (! is_string($line)) {
                    continue;
                }

                if (! str_contains($line, 'WITHDRAWAL_REFUND') || ! str_contains($line, 'restitu')) {
                    continue;
                }

                if (preg_match('/"cash_out_id":(\d+)/', $line, $m)) {
                    $id = (int) $m[1];
                    if (isset($map[$id])) {
                        continue;
                    }

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
