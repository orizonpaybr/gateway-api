<?php

namespace App\Observers;

use App\Models\User;
use App\Services\FinancialService;
use App\Services\CacheKeyService;
use Illuminate\Support\Facades\Log;

/**
 * Observer para modelo User
 * 
 * Responsabilidades:
 * - Invalidar cache financeiro quando saldo ou dados relevantes são atualizados
 * - Manter consistência de cache em operações críticas
 */
class UserObserver
{
    /**
     * Campos que quando alterados devem invalidar cache financeiro
     */
    private const FINANCIAL_RELATED_FIELDS = [
        'saldo',
        'total_transacoes',
        'valor_sacado',
        'status',
    ];

    /**
     * Handle the User "created" event.
     */
    public function created(User $user): void
    {
        // Invalidar cache de estatísticas quando novo usuário é criado
        $this->invalidateFinancialCache();
        
        // Invalidar cache de usuários admin
        CacheKeyService::forgetUsersStats();
    }

    /**
     * Handle the User "updated" event.
     */
    public function updated(User $user): void
    {
        // Verificar se algum campo financeiro foi alterado
        $financialFieldsChanged = false;
        
        foreach (self::FINANCIAL_RELATED_FIELDS as $field) {
            if ($user->wasChanged($field)) {
                $financialFieldsChanged = true;
                
                Log::debug('Campo financeiro alterado no User', [
                    'user_id' => $user->id,
                    'field' => $field,
                    'old_value' => $user->getOriginal($field),
                    'new_value' => $user->getAttribute($field),
                ]);
                break;
            }
        }

        // Invalidar cache financeiro se campos relevantes foram alterados
        if ($financialFieldsChanged) {
            $this->invalidateFinancialCache();
        }

        // Sempre invalidar cache de usuários admin quando usuário é atualizado
        CacheKeyService::forgetUser($user->id);
        CacheKeyService::forgetUsersStats();
    }

    /**
     * Handle the User "deleted" event.
     */
    public function deleted(User $user): void
    {
        // Invalidar cache quando usuário é deletado
        $this->invalidateFinancialCache();
        CacheKeyService::forgetUser($user->id);
        CacheKeyService::forgetUsersStats();
    }

    /**
     * Invalidar cache financeiro relacionado
     */
    private function invalidateFinancialCache(): void
    {
        try {
            $financialService = app(FinancialService::class);
            $financialService->invalidateWalletsCache();
            $financialService->invalidateStatsCache();
        } catch (\Exception $e) {
            Log::warning('Erro ao invalidar cache financeiro no UserObserver', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

