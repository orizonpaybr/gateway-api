<?php

namespace App\Listeners;

use App\Events\LevelUpdated;
use App\Services\CacheKeyService;
use Illuminate\Support\Facades\Log;

/**
 * Listener para invalidar cache de gamificação
 *
 * Escuta eventos de níveis e limpa apenas o cache de gamificação
 */
class InvalidateGamificationCache
{
    /**
     * Handle LevelUpdated event
     */
    public function handleLevelUpdated(LevelUpdated $event): void
    {
        $this->clearCache('level_updated', $event->nivel->id);
    }

    /**
     * Limpa apenas cache de gamificação (níveis + dados por usuário)
     */
    private function clearCache(string $action, int $nivelId): void
    {
        try {
            CacheKeyService::forgetGamificationAll();

            Log::info('Cache de gamificação limpo após evento', [
                'action' => $action,
                'nivel_id' => $nivelId,
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao limpar cache de gamificação após evento', [
                'action' => $action,
                'nivel_id' => $nivelId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

