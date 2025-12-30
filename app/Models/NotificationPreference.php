<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class NotificationPreference extends Model
{
    use HasFactory;

    protected $table = 'notification_preferences';

    protected $fillable = [
        'user_id',
        'notify_transactions',
        'notify_deposits',
        'notify_withdrawals',
        'notify_security',
        'notify_system',
    ];

    protected $casts = [
        'notify_transactions' => 'boolean',
        'notify_deposits' => 'boolean',
        'notify_withdrawals' => 'boolean',
        'notify_security' => 'boolean',
        'notify_system' => 'boolean',
    ];

    /**
     * Relacionamento com usuário
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'username');
    }

    /**
     * Verificar se usuário deve receber notificação de um tipo específico
     * 
     * @param string $userId
     * @param string $type (transactions|deposits|withdrawals|security|system)
     * @return bool
     * 
     * @deprecated Use NotificationPreferenceService::shouldNotify() instead
     */
    public static function shouldNotify(string $userId, string $type): bool
    {
        $preferences = self::firstOrCreate(
            ['user_id' => $userId],
            [
                'notify_transactions' => true,
                'notify_deposits' => true,
                'notify_withdrawals' => true,
                'notify_security' => true,
                'notify_system' => true,
            ]
        );

        return match($type) {
            'transaction', 'transactions' => $preferences->notify_transactions,
            'deposit', 'deposits' => $preferences->notify_deposits,
            'withdraw', 'withdrawal', 'withdrawals' => $preferences->notify_withdrawals,
            'security' => $preferences->notify_security,
            'system' => $preferences->notify_system,
            default => true,
        };
    }

    /**
     * Limpar cache ao atualizar
     */
    protected static function boot()
    {
        parent::boot();

        static::saved(function ($preference) {
            Cache::forget("notification_preferences:{$preference->user_id}");
        });

        static::deleted(function ($preference) {
            Cache::forget("notification_preferences:{$preference->user_id}");
        });
    }
}

