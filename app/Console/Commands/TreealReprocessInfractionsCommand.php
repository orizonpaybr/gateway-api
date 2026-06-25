<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\TreealContasWebhookController;
use App\Services\TreealContas\TreealContasInfractionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reprocessa infrações (MED) da Treeal que chegaram via webhook mas ficaram como
 * "deposit_not_found" — normalmente porque o depósito não tinha end_to_end preenchido.
 *
 * Uso:
 *   php artisan treeal:reprocess-infractions           # busca últimos 30 dias
 *   php artisan treeal:reprocess-infractions --dry-run # só mostra, não aplica
 *   php artisan treeal:reprocess-infractions --days=7  # janela menor
 *   php artisan treeal:reprocess-infractions --id=3046d13e-2e6e-4dd9-a5d9-75eae0e26483
 */
class TreealReprocessInfractionsCommand extends Command
{
    protected $signature = 'treeal:reprocess-infractions
                            {--id= : Reprocessa um único provider_infraction_id}
                            {--days=30 : Janela de busca na API Treeal (dias atrás)}
                            {--dry-run : Apenas lista o que seria feito, sem alterar}';

    protected $description = 'Reprocessa MEDs da Treeal que ficaram sem match de depósito (deposit_not_found)';

    public function handle(TreealContasInfractionService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $singleId = trim((string) ($this->option('id') ?? ''));

        if (! $service->isConfigured()) {
            $this->error('TREEAL Contas não configurada — verifique TREEAL_CONTAS_* no .env.');
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('[DRY-RUN] Nenhuma alteração será feita.');
        }

        // ── Modo ID único ──────────────────────────────────────────────────
        if ($singleId !== '') {
            return $this->reprocessOne($service, $singleId, $dryRun);
        }

        // ── Busca todas as infrações abertas na Treeal ─────────────────────
        $days = max(1, (int) ($this->option('days') ?? 30));
        $this->info("Buscando infrações dos últimos {$days} dias na API Treeal...");

        $result = $service->listInfractions([
            'last_change_start' => Carbon::now()->subDays($days)->toIso8601String(),
            'last_change_end'   => Carbon::now()->toIso8601String(),
            'page_limit'        => 100,
        ]);

        if (! ($result['success'] ?? false)) {
            $this->error('Falha ao listar infrações: '.($result['message'] ?? 'erro desconhecido'));
            return self::FAILURE;
        }

        $raw = $result['raw'] ?? [];
        $items = $raw['data'] ?? (array_is_list($raw) ? $raw : []);

        if (empty($items)) {
            $this->info('Nenhuma infração encontrada na API Treeal para o período.');
            return self::SUCCESS;
        }

        $this->info(count($items).' infrações encontradas. Reprocessando (idempotente)...');

        $processed = 0;
        $failed    = 0;

        foreach ($items as $item) {
            $infractionId = trim((string) ($item['id'] ?? ''));
            if ($infractionId === '') {
                continue;
            }

            // Reprocessamento é idempotente (upsert + aplicação de status só muda estados
            // aplicáveis), então reprocessamos tudo — inclusive infrações já registradas
            // que não chegaram a bloquear o depósito por algum motivo.
            $status = $this->reprocessOne($service, $infractionId, $dryRun, $item);
            if ($status === self::SUCCESS) {
                $processed++;
            } else {
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Concluído: {$processed} reprocessadas | {$failed} falhas");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function reprocessOne(
        TreealContasInfractionService $service,
        string $infractionId,
        bool $dryRun,
        array $item = [],
    ): int {
        // Busca detalhe se não tiver o payload completo.
        if (empty($item) || empty($item['endToEndId'] ?? '')) {
            $detail = $service->getInfraction($infractionId);
            if (! ($detail['success'] ?? false)) {
                $this->error("  ERRO ao buscar detalhe de {$infractionId}: ".($detail['message'] ?? ''));
                return self::FAILURE;
            }
            $raw  = $detail['raw'] ?? [];
            $item = is_array($raw['data'] ?? null) ? $raw['data'] : ($item ?: $raw);
        }

        $e2e    = trim((string) ($item['endToEndId'] ?? ''));
        $status = strtoupper(trim((string) ($item['status'] ?? 'OPEN')));
        $amount = (float) ($item['transactionAmount']['amount'] ?? $item['amount'] ?? 0);

        $this->line("  → {$infractionId} | status={$status} | e2e={$e2e} | R\$ {$amount}");

        if ($dryRun) {
            return self::SUCCESS;
        }

        // Monta payload no mesmo formato que o webhook original e dispara o handler.
        $payload = array_merge($item, [
            'id'           => $infractionId,
            'status'       => $status,
            'endToEndId'   => $e2e,
            'transactionAmount' => ['amount' => $amount, 'currency' => 'BRL'],
        ]);

        try {
            // O handler valida o header de autenticação do webhook (passesOptionalAuthHeader).
            // Como o request é fabricado internamente, injetamos o header esperado quando configurado.
            $server = [];
            $authHeader = trim((string) config('treeal_contas.webhook_auth_header', ''));
            $authValue  = (string) config('treeal_contas.webhook_auth_value', '');
            if ($authHeader !== '' && $authValue !== '') {
                $serverKey = 'HTTP_'.strtoupper(str_replace('-', '_', $authHeader));
                $server[$serverKey] = $authValue;
            }

            $request = Request::create(
                '/treeal/contas/webhook',
                'POST',
                ['type' => 'INFRACTION', 'data' => $payload],
                [],
                [],
                $server,
            );

            $controller = app(TreealContasWebhookController::class);
            $response   = $controller->handle($request);
            $body       = $response->getData(true);

            $processed = $body['processed'] ?? false;
            $reason    = $body['reason'] ?? '';

            if ($processed) {
                $this->info("    <fg=green>✓ Processada com sucesso</>");
            } else {
                $this->warn("    ⚠ Não processada — reason: {$reason}");
            }

            Log::info('[TREEAL][REPROCESS_INFRACTION] Reprocessamento manual', [
                'infraction_id' => $infractionId,
                'processed'     => $processed,
                'reason'        => $reason,
            ]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("    ERRO: ".$e->getMessage());
            Log::error('[TREEAL][REPROCESS_INFRACTION] Falha', [
                'infraction_id' => $infractionId,
                'error'         => $e->getMessage(),
            ]);
            return self::FAILURE;
        }
    }
}
