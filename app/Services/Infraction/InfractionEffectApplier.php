<?php

namespace App\Services\Infraction;

use App\Models\Solicitacoes;
use App\Models\User;
use App\Services\AffiliateCommissionService;
use App\Services\BalanceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Efeito compartilhado de infração/MED, agnóstico de adquirente:
 *  - upsert idempotente do registro local (pix_infracoes)
 *  - transições de saldo do depósito (bloqueio / devolução / liberação)
 *
 * Cada webhook de adquirente (Treeal, Simpay, ...) mapeia seu payload para os
 * atributos normalizados e delega aqui, eliminando a duplicação da lógica
 * financeira sensível.
 */
final class InfractionEffectApplier
{
    /**
     * Cria/atualiza o registro local da infração (idempotente por provider_infraction_id;
     * fallback por end_to_end + user quando a coluna não existir).
     *
     * @param  array<string, mixed>  $attributes  campos já mapeados (user_id, status, tipo,
     *                                             descricao, valor, end_to_end, data_criacao,
     *                                             data_limite, detalhes, analysis_result?)
     */
    public function upsert(string $provider, string $providerInfractionId, array $attributes): void
    {
        $attributes['updated_at'] = Carbon::now();

        if (! Schema::hasColumn('pix_infracoes', 'analysis_result')) {
            unset($attributes['analysis_result']);
        }

        if (Schema::hasColumn('pix_infracoes', 'provider_infraction_id')) {
            $attributes['provider'] = $provider;

            $existing = DB::table('pix_infracoes')->where('provider_infraction_id', $providerInfractionId)->first();
            if ($existing) {
                DB::table('pix_infracoes')->where('id', $existing->id)->update($attributes);
            } else {
                $attributes['provider_infraction_id'] = $providerInfractionId;
                $attributes['created_at'] = Carbon::now();
                DB::table('pix_infracoes')->insert($attributes);
            }

            return;
        }

        // Fallback sem coluna de id externo: deduplica por end_to_end + user.
        $existing = DB::table('pix_infracoes')
            ->where('user_id', (string) ($attributes['user_id'] ?? ''))
            ->where('end_to_end', (string) ($attributes['end_to_end'] ?? ''))
            ->first();

        if ($existing) {
            DB::table('pix_infracoes')->where('id', $existing->id)->update($attributes);
        } else {
            $attributes['created_at'] = Carbon::now();
            DB::table('pix_infracoes')->insert($attributes);
        }
    }

    /**
     * Infração ativa: bloqueia o valor (MEDIATION) se ainda estava creditado.
     */
    public function hold(Solicitacoes $deposit): bool
    {
        return DB::transaction(function () use ($deposit) {
            $locked = Solicitacoes::where('id', $deposit->id)->lockForUpdate()->first();
            if (! $locked || ! in_array($locked->status, ['PAID_OUT', 'COMPLETED'], true)) {
                return false;
            }

            $locked->update(['status' => 'MEDIATION']);

            return true;
        });
    }

    /**
     * Fraude confirmada: devolve (REFUNDED) e estorna do saldo + reverte comissão
     * de afiliado, apenas se o valor havia sido creditado.
     */
    public function refund(Solicitacoes $deposit): bool
    {
        return DB::transaction(function () use ($deposit) {
            $locked = Solicitacoes::where('id', $deposit->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'REFUNDED') {
                return false;
            }

            $previousStatus = (string) $locked->status;
            $locked->update(['status' => 'REFUNDED']);

            if (in_array($previousStatus, ['PAID_OUT', 'COMPLETED', 'MEDIATION'], true)) {
                $user = User::where('user_id', $locked->user_id)
                    ->orWhere('username', $locked->user_id)
                    ->lockForUpdate()
                    ->first();

                if ($user) {
                    app(BalanceService::class)->decrementBalanceForRefund(
                        $user,
                        (float) $locked->deposito_liquido,
                        'saldo',
                    );

                    try {
                        app(AffiliateCommissionService::class)
                            ->reverseCashInCommissionForRefundedDeposit($locked);
                    } catch (\Throwable $e) {
                        Log::warning('[INFRACTION] Falha ao estornar comissão de afiliado', [
                            'deposit_id' => $locked->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            return true;
        });
    }

    /**
     * Infração encerrada sem fraude / cancelada: libera o hold (COMPLETED).
     */
    public function release(Solicitacoes $deposit): bool
    {
        return DB::transaction(function () use ($deposit) {
            $locked = Solicitacoes::where('id', $deposit->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'MEDIATION') {
                return false;
            }

            $locked->update(['status' => 'COMPLETED']);

            return true;
        });
    }
}
