<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
/**
 * Service para operações de saldo thread-safe
 * 
 * Garante:
 * - Locks pessimistas para evitar race conditions
 * - Transações atômicas
 * - Operações seguras em ambiente concorrente
 */
class BalanceService
{
    /**
     * Incrementa saldo de forma thread-safe
     * 
     * @param User $user
     * @param float $amount
     * @param string $field Campo a incrementar (saldo, valor_saque_pendente, etc)
     * @return User Usuário atualizado
     * @throws \Exception Se operação falhar
     */
    public function incrementBalance(User $user, float $amount, string $field = 'saldo'): User
    {
        return DB::transaction(function () use ($user, $amount, $field) {
            // Lock pessimista - bloqueia outras threads até commit
            $user = User::where('id', $user->id)
                ->lockForUpdate()
                ->first();
            
            if (!$user) {
                throw new \Exception("Usuário não encontrado: {$user->id}");
            }
            
            // Incremento atômico no banco (não depende de valor em memória)
            User::where('id', $user->id)
                ->increment($field, $amount);
            
            Log::info("Saldo incrementado com sucesso", [
                'user_id' => $user->user_id,
                'field' => $field,
                'amount' => $amount,
                'balance_before' => $user->$field,
                'balance_after' => $user->fresh()->$field,
            ]);
            
            // Retornar usuário atualizado
            return $user->fresh();
        });
    }
    
    /**
     * Decrementa saldo de forma thread-safe
     * 
     * @param User $user
     * @param float $amount
     * @param string $field Campo a decrementar (saldo, valor_saque_pendente, etc)
     * @return User Usuário atualizado
     * @throws \Exception Se saldo insuficiente ou operação falhar
     */
    public function decrementBalance(User $user, float $amount, string $field = 'saldo'): User
    {
        return DB::transaction(function () use ($user, $amount, $field) {
            // Lock pessimista
            $user = User::where('id', $user->id)
                ->lockForUpdate()
                ->first();
            
            if (!$user) {
                throw new \Exception("Usuário não encontrado: {$user->id}");
            }
            
            // Verificar saldo suficiente (se for saldo)
            if ($field === 'saldo' && $user->saldo < $amount) {
                throw new \Exception('Saldo insuficiente.');
            }
            
            // Decremento atômico no banco
            User::where('id', $user->id)
                ->decrement($field, $amount);
            
            Log::info("Saldo decrementado com sucesso", [
                'user_id' => $user->user_id,
                'field' => $field,
                'amount' => $amount,
                'balance_before' => $user->$field,
                'balance_after' => $user->fresh()->$field,
            ]);
            
            return $user->fresh();
        });
    }

    /**
     * Decrementa saldo para estorno (permite saldo negativo).
     * Usado quando um depósito já creditado é estornado pela adquirente.
     *
     * @param User $user
     * @param float $amount
     * @param string $field Campo a decrementar (ex.: saldo)
     * @return User Usuário atualizado
     */
    public function decrementBalanceForRefund(User $user, float $amount, string $field = 'saldo'): User
    {
        $userId = $user->id;
        return DB::transaction(function () use ($userId, $amount, $field) {
            $user = User::where('id', $userId)
                ->lockForUpdate()
                ->first();

            if (!$user) {
                throw new \Exception("Usuário não encontrado: {$userId}");
            }

            User::where('id', $user->id)->decrement($field, $amount);
            $user = $user->fresh();

            Log::info('Saldo debitado por estorno de depósito', [
                'user_id'        => $user->user_id,
                'field'          => $field,
                'amount'         => $amount,
                'balance_after'  => $user->$field,
            ]);

            return $user;
        });
    }

    /**
     * Atualiza saldo de forma thread-safe (set absoluto)
     * 
     * @param User $user
     * @param float $newValue
     * @param string $field Campo a atualizar
     * @return User Usuário atualizado
     */
    public function setBalance(User $user, float $newValue, string $field = 'saldo'): User
    {
        return DB::transaction(function () use ($user, $newValue, $field) {
            $user = User::where('id', $user->id)
                ->lockForUpdate()
                ->first();
            
            if (!$user) {
                throw new \Exception("Usuário não encontrado: {$user->id}");
            }
            
            $oldValue = $user->$field;
            
            User::where('id', $user->id)
                ->update([$field => $newValue]);
            
            Log::info("Saldo atualizado", [
                'user_id' => $user->user_id,
                'field' => $field,
                'old_value' => $oldValue,
                'new_value' => $newValue,
            ]);
            
            return $user->fresh();
        });
    }

    /**
     * Obtém o saldo total disponível (saldo principal + saldo de afiliados)
     * 
     * @param User $user
     * @return float Saldo total disponível
     */
    public function getTotalAvailableBalance(User $user): float
    {
        return $this->getBalanceBreakdown($user)['saldo_disponivel'];
    }

    /**
     * Saldo bruto, retido em mediação (MED) e disponível para saque.
     *
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

    /**
     * Soma dos depósitos retidos em mediação (infração/MED) do usuário.
     *
     * Esses valores foram creditados no saldo no momento do depósito, mas estão
     * bloqueados enquanto a MED está aberta (status MEDIATION). Por isso precisam
     * ser descontados do saldo disponível para saque. Quando a MED é encerrada,
     * o depósito sai de MEDIATION (REFUNDED debita o saldo; COMPLETED libera),
     * então este hold deixa de contar — sem dupla contagem.
     *
     * Depósitos gravam user_id = username (vide DepositController).
     */
    public function getMediationHoldAmount(User $user): float
    {
        return $this->getBalanceBreakdown($user)['saldo_em_mediacao'];
    }

    /**
     * Debita valor do saldo combinado (saldo_afiliado primeiro, depois saldo)
     * Thread-safe, com lock pessimista
     *
     * @param  User  $user
     * @param  float  $amount  Valor total a debitar
     * @return User Usuário atualizado
     *
     * @throws \Exception Se saldo total insuficiente
     */
    public function decrementCombinedBalance(User $user, float $amount): User
    {
        return $this->decrementCombinedBalanceWithSplit($user, $amount)['user'];
    }

    /**
     * Igual a decrementCombinedBalance, mas retorna o split debitado para estorno fiel.
     *
     * @return array{user: User, debito_saldo_afiliado: float, debito_saldo_principal: float}
     *
     * @throws \Exception Se saldo total insuficiente
     */
    public function decrementCombinedBalanceWithSplit(User $user, float $amount): array
    {
        if (DB::transactionLevel() > 0) {
            return $this->decrementCombinedBalanceInner($user, $amount);
        }

        return DB::transaction(fn () => $this->decrementCombinedBalanceInner($user, $amount));
    }

    /**
     * Devolve ao usuário o mesmo split debitado por decrementCombinedBalance (estorno de saque falho/cancelado).
     *
     * @param  float  $debitoSaldoAfiliado  Valor que saiu de saldo_afiliado
     * @param  float  $debitoSaldoPrincipal  Valor que saiu de saldo
     */
    public function incrementCombinedBalanceMirror(User $user, float $debitoSaldoAfiliado, float $debitoSaldoPrincipal): User
    {
        if (DB::transactionLevel() > 0) {
            return $this->incrementCombinedBalanceMirrorInner($user, $debitoSaldoAfiliado, $debitoSaldoPrincipal);
        }

        return DB::transaction(function () use ($user, $debitoSaldoAfiliado, $debitoSaldoPrincipal) {
            return $this->incrementCombinedBalanceMirrorInner($user, $debitoSaldoAfiliado, $debitoSaldoPrincipal);
        });
    }

    /**
     * @return array{user: User, debito_saldo_afiliado: float, debito_saldo_principal: float}
     */
    private function decrementCombinedBalanceInner(User $user, float $amount): array
    {
        $user = User::where('id', $user->id)
            ->lockForUpdate()
            ->first();

        if (! $user) {
            throw new \Exception("Usuário não encontrado: {$user->id}");
        }

        $totalDisponivel = $user->saldo + $user->saldo_afiliado;

        if ($totalDisponivel < $amount) {
            throw new \Exception('Saldo insuficiente.');
        }

        $saldoAfiliadoAntes = $user->saldo_afiliado;
        $saldoAntes = $user->saldo;
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
            'total_before' => $saldoAfiliadoAntes + $saldoAntes,
            'total_after' => $user->saldo_afiliado + $user->saldo,
        ]);

        CacheKeyService::forgetAffiliateUser($user->id);

        return [
            'user' => $user,
            'debito_saldo_afiliado' => $splitAfiliado,
            'debito_saldo_principal' => $splitPrincipal,
        ];
    }

    private function incrementCombinedBalanceMirrorInner(User $user, float $debitoSaldoAfiliado, float $debitoSaldoPrincipal): User
    {
        $user = User::where('id', $user->id)
            ->lockForUpdate()
            ->first();

        if (! $user) {
            throw new \Exception("Usuário não encontrado: {$user->id}");
        }

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

        CacheKeyService::forgetAffiliateUser($user->id);

        return $user;
    }
}
