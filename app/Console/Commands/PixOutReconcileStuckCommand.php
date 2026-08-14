<?php

namespace App\Console\Commands;

use App\Models\SolicitacoesCashOut;
use App\Services\Fyhub\FyhubCashOutOutcomeService;
use App\Services\Treeal\TreealCashOutOutcomeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reconcilia saques presos em PENDING/PROCESSING perguntando o status real à
 * adquirente. Rede de segurança da trava "um saque por vez": se um Pix Out ficar
 * em voo (processo morreu, webhook nunca chegou), esta rotina resolve pela verdade
 * do provedor — COMPLETED confirma, FAILED estorna via caminho auditado.
 *
 * Cobre treeal e fyhub: flux já tem job próprio (ReconcileFluxPaymentsPayoutsJob).
 * Treeal/fyhub tinham reconciliador de DEPÓSITO mas nenhum de PAYOUT — era esse o
 * buraco que deixou os saques treed de mai/jun presos.
 *
 * NUNCA credita saldo por conta própria: só chama pollApiAndApplyIfTerminal, que
 * aplica o estorno apenas quando o provedor CONFIRMA falha. Assim não há risco de
 * devolver saldo de um PIX que saiu.
 */
class PixOutReconcileStuckCommand extends Command
{
    protected $signature = 'pixout:reconcile-stuck
                            {--min-age=3 : Idade mínima em minutos (evita correr com o fluxo síncrono)}
                            {--max-age=2880 : Idade máxima em minutos (ignora legado abandonado; padrão 48h)}
                            {--limit=200 : Máximo de linhas por execução}';

    protected $description = 'Resolve saques presos em PENDING/PROCESSING consultando a adquirente';

    /** Adquirentes SEM job de reconciliação de payout próprio (flux já tem). */
    private const POLL_SERVICES = [
        'fyhub' => FyhubCashOutOutcomeService::class,
        'treeal' => TreealCashOutOutcomeService::class,
    ];

    public function handle(): int
    {
        $minAge = max(1, (int) $this->option('min-age'));
        $maxAge = max($minAge + 1, (int) $this->option('max-age'));

        $adquirentes = array_keys(self::POLL_SERVICES);

        $stuck = SolicitacoesCashOut::query()
            ->whereIn('status', SolicitacoesCashOut::IN_FLIGHT_STATUSES)
            ->whereIn('executor_ordem', $adquirentes)
            ->where('created_at', '<', now()->subMinutes($minAge))
            ->where('created_at', '>', now()->subMinutes($maxAge))
            ->orderBy('created_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('Nenhum saque preso para reconciliar.');

            return self::SUCCESS;
        }

        $resolvidos = 0;
        $pendentes = 0;
        foreach ($stuck as $payout) {
            $ref = strtolower(trim((string) $payout->executor_ordem));
            $serviceClass = self::POLL_SERVICES[$ref] ?? null;

            if ($serviceClass === null) {
                // Adquirente sem poll conhecido (ex.: HeartPay legado): não adivinhar.
                Log::warning('[PIXOUT_RECONCILE] Sem serviço de poll para adquirente — pulando', [
                    'cash_out_id' => $payout->id,
                    'executor_ordem' => $payout->executor_ordem,
                    'status' => $payout->status,
                ]);
                continue;
            }

            try {
                $final = app($serviceClass)->pollApiAndApplyIfTerminal($payout);
                if ($final !== null) {
                    $resolvidos++;
                    $this->line("#{$payout->id} ({$ref}) → {$final}");
                } else {
                    $pendentes++;
                }
            } catch (\Throwable $e) {
                $pendentes++;
                Log::error('[PIXOUT_RECONCILE] Falha ao consultar adquirente', [
                    'cash_out_id' => $payout->id,
                    'executor_ordem' => $payout->executor_ordem,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf(
            'Reconciliação: %d analisados, %d resolvidos, %d ainda em aberto.',
            $stuck->count(),
            $resolvidos,
            $pendentes
        ));

        // Saques que passaram do máximo e seguem presos: exigem olho humano.
        $muitoVelhos = SolicitacoesCashOut::query()
            ->whereIn('status', SolicitacoesCashOut::IN_FLIGHT_STATUSES)
            ->whereIn('executor_ordem', $adquirentes)
            ->where('created_at', '<', now()->subMinutes($maxAge))
            ->count();
        if ($muitoVelhos > 0) {
            Log::critical('[PIXOUT_RECONCILE] Saques presos além do limite — revisão manual', [
                'quantidade' => $muitoVelhos,
                'max_age_min' => $maxAge,
            ]);
            $this->warn("{$muitoVelhos} saque(s) preso(s) além de {$maxAge}min exigem revisão manual (Log::critical).");
        }

        return self::SUCCESS;
    }
}
