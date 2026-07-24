<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Detecta PIX pago sem débito de saldo correspondente — o vazamento que passou
 * ~2 meses invisível (R$ 26k em treeal/mai-jun e fluxpayments/jul).
 *
 * Assinatura do vazamento: solicitacoes_cash_out COMPLETED com debito_saldo_*
 * NULL, ou seja, a linha nunca foi debitada mas o PIX saiu do banco.
 */
class PixOutAuditLeakCommand extends Command
{
    protected $signature = 'pixout:audit-leak
                            {--days=7 : Janela em dias a analisar}
                            {--user= : Filtra por username/user_id}
                            {--conciliar : Também reconcilia o saldo de cada cliente afetado}';

    protected $description = 'Aponta saques COMPLETED pagos sem débito de saldo (dinheiro que saiu sem cobertura)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $desde = now()->subDays($days)->startOfDay();

        $q = DB::table('solicitacoes_cash_out')
            ->where('status', 'COMPLETED')
            ->whereNull('debito_saldo_principal')
            ->whereNotNull('valor_total_descontado')
            ->where('created_at', '>=', $desde);

        if ($user = $this->option('user')) {
            $q->where('user_id', $user);
        }

        $linhas = $q->select('id', 'user_id', 'amount', 'valor_total_descontado', 'executor_ordem', 'idTransaction', 'externalreference', 'created_at')
            ->orderBy('created_at')
            ->get();

        if ($linhas->isEmpty()) {
            $this->info("OK — nenhum saque pago sem débito nos últimos {$days} dia(s).");

            return self::SUCCESS;
        }

        $total = 0.0;
        $rows = [];
        foreach ($linhas as $l) {
            $total += (float) $l->valor_total_descontado;
            // Órfão da ordem antiga (payout antes do débito): o update pós-payout nunca
            // rodou, então idTransaction ficou igual ao correlationID gerado localmente.
            $orfaoOrdemAntiga = $l->idTransaction === $l->externalreference;
            $rows[] = [
                $l->id,
                $l->user_id,
                'R$ '.number_format((float) $l->valor_total_descontado, 2, ',', '.'),
                $l->executor_ordem,
                $orfaoOrdemAntiga ? 'payout-antes-do-debito' : 'investigar',
                $l->created_at,
            ];
        }

        $this->error(sprintf(
            'VAZAMENTO: %d saque(s) pagos sem débito — R$ %s',
            $linhas->count(),
            number_format($total, 2, ',', '.')
        ));
        $this->table(['id', 'cliente', 'valor', 'adquirente', 'causa provável', 'criado em'], $rows);

        if ($this->option('conciliar')) {
            $this->conciliar($linhas->pluck('user_id')->unique()->filter()->all());
        }

        return self::FAILURE;
    }

    /**
     * Confere, por cliente, se o saldo atual bate com créditos - débitos registados.
     *
     * @param  array<int, string>  $usernames
     */
    private function conciliar(array $usernames): void
    {
        $this->newLine();
        $this->info('Conciliação de saldo dos clientes afetados:');

        $rows = [];
        foreach ($usernames as $un) {
            $u = DB::table('users')
                ->where('username', $un)
                ->orWhere('user_id', $un)
                ->select('id', 'saldo', 'saldo_afiliado')
                ->first();
            if (! $u) {
                continue;
            }

            $credito = (float) DB::table('solicitacoes')
                ->where('user_id', $un)->where('status', 'PAID_OUT')->sum('deposito_liquido');
            // Só saques terminais: os em voo mantêm o débito legitimamente reservado.
            $debito = (float) DB::table('solicitacoes_cash_out')
                ->where('user_id', $un)
                ->sum(DB::raw('coalesce(debito_saldo_principal,0)+coalesce(debito_saldo_afiliado,0)'));
            $ajustes = (float) DB::table('balance_ledger_entries')
                ->where('user_id', $u->id)->where('reason', 'admin_adjust')->sum('delta');
            $pagoSemDebito = (float) DB::table('solicitacoes_cash_out')
                ->where('user_id', $un)->where('status', 'COMPLETED')
                ->whereNull('debito_saldo_principal')->sum('valor_total_descontado');

            $real = (float) $u->saldo + (float) $u->saldo_afiliado;
            $esperado = $credito - $debito + $ajustes;

            $rows[] = [
                $un,
                'R$ '.number_format($real, 2, ',', '.'),
                'R$ '.number_format($esperado, 2, ',', '.'),
                'R$ '.number_format($real - $esperado, 2, ',', '.'),
                'R$ '.number_format($pagoSemDebito, 2, ',', '.'),
            ];
        }

        $this->table(['cliente', 'saldo real', 'saldo esperado', 'diferença', 'pago s/ débito'], $rows);
        $this->warn('Nota: marcador de débito em linha FAILED/CANCELLED pode estar sujo após estorno — a diferença é indicativa, não prova.');
    }
}
