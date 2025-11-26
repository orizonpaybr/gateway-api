<?php

namespace App\Listeners;

use App\Events\{LevelUpdated};
use Illuminate\Support\Facades\Log;

/**
 * Listener para auditoria de mudanças em níveis
 * 
 * Registra todas as alterações para fins de auditoria
 * 
 * @package App\Listeners
 */
class LogLevelChanges
{
    /**
     * Handle LevelUpdated event
     *
     * @param LevelUpdated $event
     * @return void
     */
    public function handleLevelUpdated(LevelUpdated $event): void
    {
        Log::channel('daily')->info('Nível atualizado', [
            'nivel_id' => $event->nivel->id,
            'nivel' => [
                'nome' => $event->nivel->nome,
                'minimo' => $event->nivel->minimo,
                'maximo' => $event->nivel->maximo,
            ],
            'user_id' => $event->userId,
            'timestamp' => now()->toISOString(),
        ]);
    }
}

