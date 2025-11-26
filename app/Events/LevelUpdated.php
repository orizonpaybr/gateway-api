<?php

namespace App\Events;

use App\Models\Nivel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento disparado quando um nível é atualizado
 * 
 * Usado para:
 * - Auditoria
 * - Cache invalidation
 * - Notificações
 * - Webhooks
 * 
 * @package App\Events
 */
class LevelUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Nível que foi atualizado
     *
     * @var Nivel
     */
    public Nivel $nivel;

    /**
     * Identificador do usuário que fez a alteração
     *
     * @var string|null
     */
    public ?string $userId;

    /**
     * Create a new event instance.
     *
     * @param Nivel $nivel
     * @param string|null $userId
     */
    public function __construct(Nivel $nivel, ?string $userId = null)
    {
        $this->nivel = $nivel;
        $this->userId = $userId;
    }
}

