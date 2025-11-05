<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event disparado quando um usuário é aprovado pelo admin
 * 
 * Permite implementar listeners para:
 * - Enviar notificação por email
 * - Enviar notificação push
 * - Registrar em log de auditoria
 * - Etc.
 */
class UserApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public User $user
    ) {
        //
    }
}

