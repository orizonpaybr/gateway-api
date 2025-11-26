<?php

namespace App\Listeners;

use App\Events\LevelUpdated;
use Illuminate\Support\Facades\{Cache, Log};

/**
 * Listener para invalidar cache de gamificação
 * 
 * Escuta eventos de níveis e limpa o cache automaticamente
 * 
 * @package App\Listeners
 */
class InvalidateGamificationCache
{
    /**
     * Handle LevelUpdated event
     *
     * @param LevelUpdated $event
     * @return void
     */
    public function handleLevelUpdated(LevelUpdated $event): void
    {
        $this->clearCache('level_updated', $event->nivel->id);
    }

    /**
     * Clear gamification cache
     *
     * @param string $action
     * @param int $nivelId
     * @return void
     */
    private function clearCache(string $action, int $nivelId): void
    {
        try {
            Cache::flush();

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

