<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model para rastreamento de webhooks processados
 * 
 * Garante idempotência através de idempotency_key
 */
class WebhookLog extends Model
{
    protected $fillable = [
        'idempotency_key',
        'adquirente',
        'transaction_id',
        'status',
        'payload',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Verificar se webhook já foi processado
     */
    public static function isProcessed(string $idempotencyKey, string $adquirente): bool
    {
        return self::where('idempotency_key', $idempotencyKey)
            ->where('adquirente', $adquirente)
            ->where('status', 'PROCESSED')
            ->exists();
    }

    /**
     * Obter webhook log por idempotency key
     */
    public static function findByKey(string $idempotencyKey, string $adquirente): ?self
    {
        return self::where('idempotency_key', $idempotencyKey)
            ->where('adquirente', $adquirente)
            ->first();
    }
}
