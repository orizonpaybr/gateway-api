<?php

namespace App\Console\Commands;

use App\Models\SolicitacoesCashOut;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Estorna manualmente o saldo Coratri de um saque que ficou FAILED com débito registado
 * mas sem log de [WITHDRAWAL_REFUND] (ex.: webhook nunca chegou, poll falhou).
 *
 * Idempotente: cancela se o saque já não está em FAILED ou se não há débito.
 * SEMPRE use --dry-run primeiro para confirmar o que será feito.
 */
class PixOutManualRefundCommand extends Command
{
    protected $signature = 'pixout:manual-refund
                            {id : ID em solicitacoes_cash_out}
                            {--dry-run : Mostra o que seria feito sem alterar o banco}
                            {--force : Confirma sem perguntar (usar em scripts)}';

    protected $description = 'Estorna manualmente saldo Coratri de saque FAILED com débito não restituído';

    public function handle(): int
    {
        $id = (int) $this->argument('id');
        $dryRun = (bool) $this->option('dry-run');

        $cashOut = SolicitacoesCashOut::find($id);
        if (! $cashOut) {
            $this->error("Saque #{$id} não encontrado.");

            return self::FAILURE;
        }

        $user = User::query()
            ->where('user_id', $cashOut->user_id)
            ->orWhere('username', $cashOut->user_id)
            ->first();

        if (! $user) {
            $this->error("Usuário '{$cashOut->user_id}' não encontrado.");

            return self::FAILURE;
        }

        // Validações de segurança
        if ($cashOut->status !== 'FAILED') {
            $this->error("Saque #{$id} tem status '{$cashOut->status}', não FAILED. Abortando.");

            return self::FAILURE;
        }

        $debPr = (float) ($cashOut->debito_saldo_principal ?? 0);
        $debAf = (float) ($cashOut->debito_saldo_afiliado ?? 0);
        $valorDevolver = (float) ($cashOut->valor_total_descontado ?? 0) > 0
            ? (float) $cashOut->valor_total_descontado
            : (float) $cashOut->amount + (float) ($cashOut->taxa_cash_out ?? 0);

        if ($debPr <= 0 && $debAf <= 0) {
            $this->warn("Saque #{$id}: debito_saldo_principal e debito_saldo_afiliado são ambos 0 ou nulos.");
            $this->warn('Falhou antes do débito — nada a estornar.');

            return self::SUCCESS;
        }

        if ($valorDevolver <= 0) {
            $this->error("valor_total_descontado = 0 e amount+taxa = 0 para saque #{$id}. Abortando.");

            return self::FAILURE;
        }

        $date = $cashOut->date ? Carbon::parse((string) $cashOut->date)->format('Y-m-d H:i') : '—';

        $this->table(
            ['Campo', 'Valor'],
            [
                ['ID saque', $cashOut->id],
                ['User', $cashOut->user_id],
                ['Saldo actual do user', 'R$ '.number_format((float) ($user->saldo ?? 0) + (float) ($user->saldo_afiliado ?? 0), 2, ',', '.')],
                ['idTransaction', $cashOut->idTransaction],
                ['Data', $date],
                ['Valor a devolver (total)', 'R$ '.number_format($valorDevolver, 2, ',', '.')],
                ['debito_saldo_principal', 'R$ '.number_format($debPr, 2, ',', '.')],
                ['debito_saldo_afiliado', 'R$ '.number_format($debAf, 2, ',', '.')],
            ]
        );

        if ($dryRun) {
            $this->info('[DRY-RUN] Nenhuma alteração foi feita. Remove --dry-run para executar.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Confirmar estorno de R$ ".number_format($valorDevolver, 2, ',', '.')." para '{$cashOut->user_id}'?")) {
            $this->line('Abortado pelo utilizador.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($cashOut, $user, $debPr, $debAf, $valorDevolver) {
            // Re-lock para garantir consistência
            $lockedCashOut = SolicitacoesCashOut::where('id', $cashOut->id)->lockForUpdate()->first();
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();

            if (! $lockedCashOut || $lockedCashOut->status !== 'FAILED') {
                throw new \RuntimeException("Status do saque mudou durante a operação: {$lockedCashOut?->status}");
            }

            // Usar split gravado se disponível e coerente, senão só saldo principal
            $splitCoerente = ($debAf > 0 || $debPr > 0)
                && abs(($debAf + $debPr) - round($valorDevolver, 4)) <= 0.02;

            if ($splitCoerente && ($debAf > 0 || $debPr > 0)) {
                if ($debAf > 0) {
                    User::where('id', $lockedUser->id)->increment('saldo_afiliado', round($debAf, 4));
                }
                if ($debPr > 0) {
                    User::where('id', $lockedUser->id)->increment('saldo', round($debPr, 4));
                }
            } else {
                User::where('id', $lockedUser->id)->increment('saldo', round($valorDevolver, 4));
            }

            Log::info('[MANUAL_PIXOUT_REFUND] Saldo manualmente restituído via artisan pixout:manual-refund', [
                'cash_out_id' => $lockedCashOut->id,
                'id_transaction' => $lockedCashOut->idTransaction,
                'user_id' => $lockedCashOut->user_id,
                'valor_devolvido' => $valorDevolver,
                'debito_saldo_principal' => $debPr,
                'debito_saldo_afiliado' => $debAf,
                'split_usado' => $splitCoerente,
                'operador' => get_current_user(),
            ]);
        });

        $userFresh = $user->fresh();
        $this->info("Estorno aplicado com sucesso.");
        $this->line(sprintf(
            'Saldo após estorno: principal R$ %s | afiliado R$ %s | total R$ %s',
            number_format((float) ($userFresh->saldo ?? 0), 2, ',', '.'),
            number_format((float) ($userFresh->saldo_afiliado ?? 0), 2, ',', '.'),
            number_format((float) ($userFresh->saldo ?? 0) + (float) ($userFresh->saldo_afiliado ?? 0), 2, ',', '.'),
        ));

        return self::SUCCESS;
    }
}
