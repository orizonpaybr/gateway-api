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
}
