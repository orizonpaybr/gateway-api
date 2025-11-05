<?php

namespace App\Listeners;

use App\Events\UserApproved;
use Illuminate\Support\Facades\Log;

/**
 * Listener para evento UserApproved
 * 
 * TODO: Implementar envio de notificação por email/push
 * Por enquanto, apenas registra em log
 */
class SendUserApprovalNotification
{
    /**
     * Handle the event.
     */
    public function handle(UserApproved $event): void
    {
        $user = $event->user;
        
        Log::info('Notificação de aprovação de usuário', [
            'user_id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
        ]);
        
        // TODO: Implementar envio de email
        // Mail::to($user->email)->send(new UserApprovedMail($user));
        
        // TODO: Implementar notificação push (se necessário)
        // Notification::send($user, new UserApprovedNotification($user));
    }
}

