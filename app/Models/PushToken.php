<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PushToken extends Model
{
    use HasFactory;

    protected $table = 'push_tokens';

    protected $fillable = [
        'user_id',
        'token',
        'platform',
        'device_id',
        'is_active',
        'last_used_at'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime'
    ];

    /**
     * Relacionamento com usuário
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'username');
    }

    /**
     * Marcar token como usado
     */
    public function markAsUsed()
    {
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Desativar token
     */
    public function deactivate()
    {
        $this->update(['is_active' => false]);
    }

    /**
     * Buscar tokens ativos de um usuário
     */
    public static function getActiveTokensForUser($userId)
    {
        return self::where('user_id', $userId)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Buscar token específico
     */
    public static function findByToken($token)
    {
        return self::where('token', $token)
            ->where('is_active', true)
            ->first();
    }
}
