<?php

namespace App\Console\Commands;

use App\Models\PaymentEvent;
use App\Models\Solicitacoes;
use App\Services\PaymentProcessingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Credita depósitos que vieram como LIQUIDATED (ou equivalente) e não foram creditados.
 *
 * Inclui:
 * 1) Status LIQUIDATED, CONCLUIDA, etc. (formato antigo / webhook sem job).
 * 2) Status PAID_OUT/COMPLETED que não têm evento em payment_events (crédito nunca aplicado).
 *
 * Uso na VPS:
 *   php artisan deposits:credit-liquidated --show-statuses  (só lista status que existem na base)
 *   php artisan deposits:credit-liquidated --dry-run
 *   php artisan deposits:credit-liquidated
 */
class CreditLiquidatedDeposits extends Command
{
    protected $signature = 'deposits:credit-liquidated
                            {--dry-run        : Apenas lista, não credita}
                            {--show-statuses  : Lista os status existentes na tabela solicitacoes (diagnóstico)}';

    protected $description = 'Credita depósitos LIQUIDATED ou PAID_OUT/COMPLETED que ainda não tiveram saldo creditado';

    /** Status que indicam "pago na adquirente" mas podem não ter passado pelo job de crédito. */
    private const LIQUIDATED_STATUSES = [
        'LIQUIDATED',
        'CONCLUIDA',
        'CONCLUIDO',
        'ATIVA',
        'PAID',
        'PROCESSED',
    ];

    /** Status já "pagos" no nosso sistema mas que podem não ter tido o crédito aplicado. */
    private const PAID_STATUSES = ['PAID_OUT', 'COMPLETED'];

    public function handle(PaymentProcessingService $paymentService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $showStatuses = (bool) $this->option('show-statuses');

        if ($showStatuses) {
            $this->printStatuses();
            return 0;
        }

        $this->info('Buscando depósitos não creditados (LIQUIDATED/equivalentes ou PAID_OUT/COMPLETED sem evento)...');

        $idsWithEvent = PaymentEvent::where('event_type', 'PAYMENT_RECEIVED')
            ->where('transaction_type', 'deposit')
            ->pluck('transaction_id');

        $deposits = Solicitacoes::where(function ($q) use ($idsWithEvent) {
            $q->whereIn('status', self::LIQUIDATED_STATUSES)
                ->orWhere(function ($q2) use ($idsWithEvent) {
                    $q2->whereIn('status', self::PAID_STATUSES)
                        ->whereNotIn('id', $idsWithEvent->toArray());
                });
        })
            ->orderBy('date', 'asc')
            ->get();

        if ($deposits->isEmpty()) {
            $this->info('Nenhum depósito não creditado encontrado.');
            return 0;
        }

        $this->info(sprintf('Encontrados %d depósito(s).', $deposits->count()));
        if ($dryRun) {
            $this->warn('Modo dry-run: nenhum crédito será aplicado.');
        }
        $this->newLine();

        $credited = 0;
        $errors = 0;

        foreach ($deposits as $dep) {
            $this->line(sprintf(
                '  ID: %s | TXID: %s | user_id: %s | Valor: R$ %s | Líquido: R$ %s | Status: %s',
                $dep->id,
                $dep->idTransaction ?? $dep->externalreference ?? '-',
                $dep->user_id,
                number_format((float) $dep->amount, 2, ',', '.'),
                number_format((float) ($dep->deposito_liquido ?? 0), 2, ',', '.'),
                $dep->status
            ));

            if ($dryRun) {
                $this->info('     [DRY-RUN] Seria creditado.');
                $credited++;
                continue;
            }

            try {
                $paymentService->processPaymentReceived($dep);
                $this->info('     Credito aplicado.');
                Log::info('CreditLiquidatedDeposits - Depósito creditado', [
                    'id' => $dep->id,
                    'txid' => $dep->idTransaction ?? $dep->externalreference,
                    'user_id' => $dep->user_id,
                    'amount' => $dep->amount,
                    'deposito_liquido' => $dep->deposito_liquido,
                ]);
                $credited++;
            } catch (\Throwable $e) {
                $this->error('     Erro: ' . $e->getMessage());
                Log::error('CreditLiquidatedDeposits - Erro ao creditar', [
                    'id' => $dep->id,
                    'txid' => $dep->idTransaction ?? $dep->externalreference,
                    'error' => $e->getMessage(),
                ]);
                $errors++;
            }

            $this->newLine();
        }

        $this->info('----------------------------------------');
        $this->info("Creditados : {$credited}");
        if ($errors > 0) {
            $this->error("Com erro   : {$errors}");
        }

        return $errors > 0 ? 1 : 0;
    }

    private function printStatuses(): void
    {
        $statuses = Solicitacoes::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        $this->info('Status existentes na tabela solicitacoes (depósitos):');
        $this->newLine();
        foreach ($statuses as $row) {
            $this->line('  ' . ($row->status ?? '(vazio)') . '  =>  ' . $row->total . ' registro(s)');
        }
        $this->newLine();
        $this->comment('Use --dry-run para listar os que seriam creditados sem aplicar.');
    }
}
