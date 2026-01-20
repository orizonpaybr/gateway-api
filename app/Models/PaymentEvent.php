<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model para Event Sourcing de transações financeiras
 * 
 * Registra todas as operações financeiras para auditoria completa
 */
class PaymentEvent extends Model
{
    protected $fillable = [
        'event_type',
        'transaction_id',
        'transaction_type',
        'user_id',
        'amount',
        'amount_credited',
        'amount_debited',
        'balance_before',
        'balance_after',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_credited' => 'decimal:2',
        'amount_debited' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relacionamento com usuário
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Tipos de eventos disponíveis
     */
    public const EVENT_TYPES = [
        'PAYMENT_RECEIVED' => 'Pagamento recebido (depósito)',
        'PAYMENT_SENT' => 'Pagamento enviado (saque)',
        'PAYMENT_REVERSED' => 'Pagamento revertido',
        'BALANCE_ADJUSTED' => 'Saldo ajustado manualmente',
    ];
}
