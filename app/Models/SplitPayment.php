<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SplitPayment extends Model
{
    protected $table = 'split_payments';
    
    protected $fillable = [
        'solicitacao_id',
        'user_id',
        'split_email',
        'split_percentage',
        'split_amount',
        'split_status',
        'split_type',
        'description',
        'processed_at',
        'error_message'
    ];

    protected $casts = [
        'split_percentage' => 'decimal:2',
        'split_amount' => 'decimal:2',
        'processed_at' => 'datetime',
        'split_status' => 'string'
    ];

    // Status possíveis
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    // Tipos de split
    const TYPE_PERCENTAGE = 'percentage';
    const TYPE_FIXED = 'fixed';
    const TYPE_PARTNER = 'partner';
    const TYPE_AFFILIATE = 'affiliate';

    /**
     * Relacionamento com a solicitação
     */
    public function solicitacao(): BelongsTo
    {
        return $this->belongsTo(Solicitacoes::class, 'solicitacao_id');
    }

    /**
     * Relacionamento com o usuário
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope para splits pendentes
     */
    public function scopePending($query)
    {
        return $query->where('split_status', self::STATUS_PENDING);
    }

    /**
     * Scope para splits processados
     */
    public function scopeCompleted($query)
    {
        return $query->where('split_status', self::STATUS_COMPLETED);
    }

    /**
     * Scope para splits falhados
     */
    public function scopeFailed($query)
    {
        return $query->where('split_status', self::STATUS_FAILED);
    }

    /**
     * Scope por tipo de split
     */
    public function scopeByType($query, $type)
    {
        return $query->where('split_type', $type);
    }

    /**
     * Scope por usuário
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope por período
     */
    public function scopeByPeriod($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Verifica se o split está pendente
     */
    public function isPending(): bool
    {
        return $this->split_status === self::STATUS_PENDING;
    }

    /**
     * Verifica se o split foi processado com sucesso
     */
    public function isCompleted(): bool
    {
        return $this->split_status === self::STATUS_COMPLETED;
    }

    /**
     * Verifica se o split falhou
     */
    public function isFailed(): bool
    {
        return $this->split_status === self::STATUS_FAILED;
    }

    /**
     * Marca o split como processado
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'split_status' => self::STATUS_COMPLETED,
            'processed_at' => now()
        ]);
    }

    /**
     * Marca o split como falhado
     */
    public function markAsFailed(string $errorMessage = null): void
    {
        $this->update([
            'split_status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'processed_at' => now()
        ]);
    }

    /**
     * Marca o split como processando
     */
    public function markAsProcessing(): void
    {
        $this->update([
            'split_status' => self::STATUS_PROCESSING
        ]);
    }

    /**
     * Calcula o valor do split baseado na porcentagem
     */
    public function calculateSplitAmount(float $totalAmount): float
    {
        if ($this->split_type === self::TYPE_PERCENTAGE) {
            return ($totalAmount * $this->split_percentage) / 100;
        }
        
        return $this->split_amount;
    }

    /**
     * Obtém o status formatado
     */
    public function getStatusFormattedAttribute(): string
    {
        return match($this->split_status) {
            self::STATUS_PENDING => 'Pendente',
            self::STATUS_PROCESSING => 'Processando',
            self::STATUS_COMPLETED => 'Concluído',
            self::STATUS_FAILED => 'Falhou',
            self::STATUS_CANCELLED => 'Cancelado',
            default => 'Desconhecido'
        };
    }

    /**
     * Obtém o tipo formatado
     */
    public function getTypeFormattedAttribute(): string
    {
        return match($this->split_type) {
            self::TYPE_PERCENTAGE => 'Porcentagem',
            self::TYPE_FIXED => 'Valor Fixo',
            self::TYPE_PARTNER => 'Parceiro',
            self::TYPE_AFFILIATE => 'Afiliado',
            default => 'Desconhecido'
        };
    }
}
