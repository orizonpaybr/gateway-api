<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service para operações de saldo thread-safe.
 * Quando $audit['reason'] é informado, grava em balance_ledger_entries.
 */
class BalanceService
{
    /**
     * @param  array{reason?: string, source?: string, actor_id?: int|null, ref_type?: string|null, ref_id?: string|int|null, meta?: array|null}  $audit
     */
    public function incrementBalance(User $user, float $amount, string $field = 'saldo', array $audit = []): User
    {
        return DB::transaction(function () use ($user, $amount, $field, $audit) {
            $user = User::where('id', $user->id)->lockForUpdate()->first();
            if (! $user) {
                throw new \Exception("Usuário não encontrado: {$user->id}");
            }

            $before = (float) $user->$field;
            User::where('id', $user->id)->increment($field, $amount);
            $user = $user->fresh();
            $after = (float) $user->$field;

            Log::info('Saldo incrementado com sucesso', [
                'user_id' => $user->user_id,
                'field' => $field,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'reason' => $audit['reason'] ?? null,
            ]);

            $this->auditIfNeeded($user, $field, $amount, $before, $after, $audit);

            return $user;
        });
    }

    /**
     * @param  array{reason?: string, source?: string, actor_id?: int|null, ref_type?: string|null, ref_id?: string|int|null, meta?: array|null}  $audit
     */
    public function decrementBalance(User $user, float $amount, string $field = 'saldo', array $audit = []): User
    {
        return DB::transaction(function () use ($user, $amount, $field, $audit) {
            $user = User::where('id', $user->id)->lockForUpdate()->first();
            if (! $user) {
                throw new \Exception("Usuário não encontrado: {$user->id}");
            }

            if ($field === 'saldo' && $user->saldo < $amount) {
                throw new \Exception('Saldo insuficiente.');
            }

            $before = (float) $user->$field;
            User::where('id', $user->id)->decrement($field, $amount);
            $user = $user->fresh();
            $after = (float) $user->$field;

            Log::info('Saldo decrementado com sucesso', [
                'user_id' => $user->user_id,
                'field' => $field,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'reason' => $audit['reason'] ?? null,
            ]);

            $this->auditIfNeeded($user, $field, -$amount, $before, $after, $audit);

            return $user;
        });
    }

    /**
     * @param  array{reason?: string, source?: string, actor_id?: int|null, ref_type?: string|null, ref_id?: string|int|null, meta?: array|null}  $audit
     */
    public function decrementBalanceForRefund(User $user, float $amount, string $field = 'saldo', array $audit = []): User
    {
        $userId = $user->id;

        return DB::transaction(function () use ($userId, $amount, $field, $audit) {
            $user = User::where('id', $userId)->lockForUpdate()->first();
            if (! $user) {
                throw new \Exception("Usuário não encontrado: {$userId}");
            }

            $before = (float) $user->$field;
            User::where('id', $user->id)->decrement($field, $amount);
            $user = $user->fresh();
            $after = (float) $user->$field;

            Log::info('Saldo debitado por estorno de depósito', [
                'user_id' => $user->user_id,
                'field' => $field,
                'amount' => $amount,
                'balance_after' => $after,
                'reason' => $audit['reason'] ?? null,
            ]);

            $this->auditIfNeeded($user, $field, -$amount, $before, $after, $audit ?: [
                'reason' => 'deposit_refund',
                'source' => 'BalanceService::decrementBalanceForRefund',
            ]);

            return $user;
        });
    }

    /**
     * @param  array{reason?: string, source?: string, actor_id?: int|null, ref_type?: string|null, ref_id?: string|int|null, meta?: array|null}  $audit
     */
    public function setBalance(User $user, float $newValue, string $field = 'saldo', array $audit = []): User
    {
        return DB::transaction(function () use ($user, $newValue, $field, $audit) {
            $user = User::where('id', $user->id)->lockForUpdate()->first();
            if (! $user) {
                throw new \Exception("Usuário não encontrado: {$user->id}");
            }

            $before = (float) $user->$field;
            User::where('id', $user->id)->update([$field => $newValue]);
            $user = $user->fresh();
            $after = (float) $user->$field;
            $delta = $after - $before;

            Log::info('Saldo atualizado', [
                'user_id' => $user->user_id,
                'field' => $field,
                'old_value' => $before,
                'new_value' => $after,
                'reason' => $audit['reason'] ?? null,
            ]);

            $this->auditIfNeeded($user, $field, $delta, $before, $after, $audit);

            return $user;
        });
    }

    public function getTotalAvailableBalance(User $user): float
    {
        return $this->getBalanceBreakdown($user)['saldo_disponivel'];
    }

    /**
     * @return array{
     *     saldo_bruto: float,
     *     saldo_em_mediacao: float,
     *     qtd_em_mediacao: int,
     *     saldo_disponivel: float
     * }
     */
    public function getBalanceBreakdown(User $user): array
    {
        $fresh = User::where('id', $user->id)->first(['saldo', 'saldo_afiliado', 'username']);
        $bruto = $fresh
            ? (float) ($fresh->saldo ?? 0) + (float) ($fresh->saldo_afiliado ?? 0)
            : 0.0;
        $username = $fresh->username ?? $user->username;
        $emMediacao = (float) \App\Models\Solicitacoes::query()
            ->where('user_id', $username)
            ->where('status', 'MEDIATION')
            ->sum(DB::raw('COALESCE(deposito_liquido, amount, 0)'));
        $qtdMediacao = (int) \App\Models\Solicitacoes::query()
            ->where('user_id', $username)
            ->where('status', 'MEDIATION')
            ->count();

        return [
            'saldo_bruto' => round($bruto, 2),
            'saldo_em_mediacao' => round($emMediacao, 2),
            'qtd_em_mediacao' => $qtdMediacao,
            'saldo_disponivel' => round(max(0.0, $bruto - $emMediacao), 2),
        ];
    }

    public function getMediationHoldAmount(User $user): float
    {
        return $this->getBalanceBreakdown($user)['saldo_em_mediacao'];
    }

    public function decrementCombinedBalance(User $user, float $amount): User
    {
        return $this->decrementCombinedBalanceWithSplit($user, $amount)['user'];
    }

    /**
     * @param  array{reason?: string, source?: string, actor_id?: int|null, ref_type?: string|null, ref_id?: string|int|null, meta?: array|null}  $audit
     * @return array{user: User, debito_saldo_afiliado: float, debito_saldo_principal: float}
     */
    public function decrementCombinedBalanceWithSplit(User $user, float $amount, array $audit = []): array
    {
        if (DB::transactionLevel() > 0) {
            return $this->decrementCombinedBalanceInner($user, $amount, $audit);
        }

        return DB::transaction(fn () => $this->decrementCombinedBalanceInner($user, $amount, $audit));
    }

    /**
     * @param  array{reason?: string, source?: string, actor_id?: int|null, ref_type?: string|null, ref_id?: string|int|null, meta?: array|null}  $audit
     */
    public function incrementCombinedBalanceMirror(
        User $user,
        float $debitoSaldoAfiliado,
        float $debitoSaldoPrincipal,
        array $audit = [],
    ): User {
        if (DB::transactionLevel() > 0) {
            return $this->incrementCombinedBalanceMirrorInner($user, $debitoSaldoAfiliado, $debitoSaldoPrincipal, $audit);
        }

        return DB::transaction(function () use ($user, $debitoSaldoAfiliado, $debitoSaldoPrincipal, $audit) {
            return $this->incrementCombinedBalanceMirrorInner($user, $debitoSaldoAfiliado, $debitoSaldoPrincipal, $audit);
        });
    }

    /**
     * @param  array{reason?: string, source?: string, actor_id?: int|null, ref_type?: string|null, ref_id?: string|int|null, meta?: array|null}  $audit
     * @return array{user: User, debito_saldo_afiliado: float, debito_saldo_principal: float}
     */
    private function decrementCombinedBalanceInner(User $user, float $amount, array $audit = []): array
    {
        $user = User::where('id', $user->id)->lockForUpdate()->first();
        if (! $user) {
            throw new \Exception("Usuário não encontrado: {$user->id}");
        }

        $totalDisponivel = $user->saldo + $user->saldo_afiliado;
        if ($totalDisponivel < $amount) {
            throw new \Exception('Saldo insuficiente.');
        }

        $saldoAfiliadoAntes = (float) $user->saldo_afiliado;
        $saldoAntes = (float) $user->saldo;
        $restante = $amount;

        $debitoAfiliado = 0.0;
        if ($user->saldo_afiliado > 0) {
            $debitoAfiliado = min($user->saldo_afiliado, $restante);
            User::where('id', $user->id)->decrement('saldo_afiliado', $debitoAfiliado);
            $restante -= $debitoAfiliado;
        }

        $debitoPrincipal = 0.0;
        if ($restante > 0) {
            $debitoPrincipal = $restante;
            User::where('id', $user->id)->decrement('saldo', $restante);
        }

        $user = $user->fresh();
        $splitAfiliado = round((float) $debitoAfiliado, 4);
        $splitPrincipal = round((float) $debitoPrincipal, 4);

        Log::info('Saldo combinado debitado com sucesso', [
            'user_id' => $user->user_id,
            'amount_total' => $amount,
            'debito_saldo_afiliado' => $debitoAfiliado,
            'debito_saldo_principal' => $debitoPrincipal,
            'saldo_afiliado_before' => $saldoAfiliadoAntes,
            'saldo_afiliado_after' => $user->saldo_afiliado,
            'saldo_before' => $saldoAntes,
            'saldo_after' => $user->saldo,
        ]);

        if ($splitAfiliado > 0) {
            $this->auditIfNeeded($user, 'saldo_afiliado', -$splitAfiliado, $saldoAfiliadoAntes, (float) $user->saldo_afiliado, $audit ?: [
                'reason' => 'withdrawal_debit',
                'source' => 'BalanceService::decrementCombinedBalance',
            ]);
        }
        if ($splitPrincipal > 0) {
            $this->auditIfNeeded($user, 'saldo', -$splitPrincipal, $saldoAntes, (float) $user->saldo, $audit ?: [
                'reason' => 'withdrawal_debit',
                'source' => 'BalanceService::decrementCombinedBalance',
            ]);
        }

        CacheKeyService::forgetAffiliateUser($user->id);

        return [
            'user' => $user,
            'debito_saldo_afiliado' => $splitAfiliado,
            'debito_saldo_principal' => $splitPrincipal,
        ];
    }

    /**
     * @param  array{reason?: string, source?: string, actor_id?: int|null, ref_type?: string|null, ref_id?: string|int|null, meta?: array|null}  $audit
     */
    private function incrementCombinedBalanceMirrorInner(
        User $user,
        float $debitoSaldoAfiliado,
        float $debitoSaldoPrincipal,
        array $audit = [],
    ): User {
        $user = User::where('id', $user->id)->lockForUpdate()->first();
        if (! $user) {
            throw new \Exception("Usuário não encontrado: {$user->id}");
        }

        $saldoAfiliadoAntes = (float) $user->saldo_afiliado;
        $saldoAntes = (float) $user->saldo;

        if ($debitoSaldoAfiliado > 0) {
            User::where('id', $user->id)->increment('saldo_afiliado', $debitoSaldoAfiliado);
        }
        if ($debitoSaldoPrincipal > 0) {
            User::where('id', $user->id)->increment('saldo', $debitoSaldoPrincipal);
        }

        $user = $user->fresh();

        Log::info('Saldo combinado restituído (espelho do débito)', [
            'user_id' => $user->user_id,
            'credito_saldo_afiliado' => $debitoSaldoAfiliado,
            'credito_saldo_principal' => $debitoSaldoPrincipal,
            'saldo_afiliado_after' => $user->saldo_afiliado,
            'saldo_after' => $user->saldo,
        ]);

        $ctx = $audit ?: [
            'reason' => 'withdrawal_refund',
            'source' => 'BalanceService::incrementCombinedBalanceMirror',
        ];
        if ($debitoSaldoAfiliado > 0) {
            $this->auditIfNeeded($user, 'saldo_afiliado', $debitoSaldoAfiliado, $saldoAfiliadoAntes, (float) $user->saldo_afiliado, $ctx);
        }
        if ($debitoSaldoPrincipal > 0) {
            $this->auditIfNeeded($user, 'saldo', $debitoSaldoPrincipal, $saldoAntes, (float) $user->saldo, $ctx);
        }

        CacheKeyService::forgetAffiliateUser($user->id);

        return $user;
    }

    /**
     * @param  array{reason?: string, source?: string, actor_id?: int|null, ref_type?: string|null, ref_id?: string|int|null, meta?: array|null}  $audit
     */
    private function auditIfNeeded(
        User $user,
        string $field,
        float $delta,
        float $before,
        float $after,
        array $audit,
    ): void {
        if (! isset($audit['reason']) || trim((string) $audit['reason']) === '') {
            return;
        }

        BalanceLedgerService::record($user, $field, $delta, $before, $after, $audit);
    }
}
