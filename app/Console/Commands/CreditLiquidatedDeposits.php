<?php

namespace App\Console\Commands;

use App\Models\Solicitacoes;
use App\Services\PaymentProcessingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Credita depósitos que vieram como LIQUIDATED (ou equivalente) e não foram creditados.
 *
 * Útil quando:
 * - Webhooks vieram no formato antigo e só atualizaram status na base sem enfileirar o job.
 * - O job de Cash In falhou e o depósito ficou com status LIQUIDATED sem crédito no saldo.
 *
 * Não consulta a API: apenas busca na base os que já estão com status "pago" (LIQUIDATED, etc.)
 * e aplica o crédito via PaymentProcessingService (idempotente).
 *
 * Uso na VPS:
 *   php artisan deposits:credit-liquidated
 *   php artisan deposits:credit-liquidated --dry-run
 */
class CreditLiquidatedDeposits extends Command
{
    protected $signature = 'deposits:credit-liquidated
                            {--dry-run : Apenas lista, não credita}';

    protected $description = 'Credita depósitos com status LIQUIDATED/equivalentes que ainda não tiveram saldo creditado';

    /** Status que indicam "pago na adquirente" mas podem não ter passado pelo job de crédito. */
    private const LIQUIDATED_STATUSES = [
        'LIQUIDATED',
        'CONCLUIDA',
        'CONCLUIDO',
        'ATIVA',
        'PAID',
        'PROCESSED',
    ];

    public function handle(PaymentProcessingService $paymentService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Buscando depósitos com status LIQUIDATED/equivalentes (não creditados)...');

        $deposits = Solicitacoes::whereIn('status', self::LIQUIDATED_STATUSES)
            ->orderBy('date', 'asc')
            ->get();

        if ($deposits->isEmpty()) {
            $this->info('Nenhum depósito nesse formato encontrado.');
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
}
