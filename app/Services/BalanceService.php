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
                throw new \Exception("Saldo insuficiente. Disponível: {$user->saldo}, Necessário: {$amount}");
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
        return (float) ($user->saldo + $user->saldo_afiliado);
    }

    /**
     * Debita valor do saldo combinado (saldo_afiliado primeiro, depois saldo)
     * Thread-safe, com lock pessimista
     * 
     * @param User $user
     * @param float $amount Valor total a debitar
     * @return User Usuário atualizado
     * @throws \Exception Se saldo total insuficiente
     */
    public function decrementCombinedBalance(User $user, float $amount): User
    {
        return DB::transaction(function () use ($user, $amount) {
            // Lock pessimista
            $user = User::where('id', $user->id)
                ->lockForUpdate()
                ->first();
            
            if (!$user) {
                throw new \Exception("Usuário não encontrado: {$user->id}");
            }
            
            $totalDisponivel = $user->saldo + $user->saldo_afiliado;
            
            // Verificar saldo total suficiente
            if ($totalDisponivel < $amount) {
                throw new \Exception(
                    "Saldo insuficiente. Disponível: R$ " . number_format($totalDisponivel, 2, ',', '.') . 
                    ", Necessário: R$ " . number_format($amount, 2, ',', '.')
                );
            }
            
            $saldoAfiliadoAntes = $user->saldo_afiliado;
            $saldoAntes = $user->saldo;
            $restante = $amount;
            
            // 1. Debitar primeiro de saldo_afiliado
            if ($user->saldo_afiliado > 0) {
                $debitoAfiliado = min($user->saldo_afiliado, $restante);
                User::where('id', $user->id)->decrement('saldo_afiliado', $debitoAfiliado);
                $restante -= $debitoAfiliado;
            }
            
            // 2. Se ainda sobrar, debitar do saldo principal
            if ($restante > 0) {
                User::where('id', $user->id)->decrement('saldo', $restante);
            }
            
            $user = $user->fresh();
            
            Log::info("Saldo combinado debitado com sucesso", [
                'user_id' => $user->user_id,
                'amount_total' => $amount,
                'saldo_afiliado_before' => $saldoAfiliadoAntes,
                'saldo_afiliado_after' => $user->saldo_afiliado,
                'saldo_before' => $saldoAntes,
                'saldo_after' => $user->saldo,
                'total_before' => $saldoAfiliadoAntes + $saldoAntes,
                'total_after' => $user->saldo_afiliado + $user->saldo,
            ]);
            
            return $user;
        });
    }
}
