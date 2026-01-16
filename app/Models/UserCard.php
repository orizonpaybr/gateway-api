<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model para cartões tokenizados dos usuários
 * 
 * Armazena apenas dados não sensíveis (PCI DSS compliant)
 * Os dados completos do cartão ficam na Pagar.me
 */
class UserCard extends Model
{
    use SoftDeletes;

    protected $table = 'user_cards';

    protected $fillable = [
        'user_id',
        'card_id',
        'customer_id',
        'brand',
        'first_six_digits',
        'last_four_digits',
        'holder_name',
        'exp_month',
        'exp_year',
        'status',
        'billing_address',
        'label',
        'is_default',
        'last_used_at',
    ];

    protected $casts = [
        'billing_address' => 'array',
        'is_default' => 'boolean',
        'exp_month' => 'integer',
        'exp_year' => 'integer',
        'last_used_at' => 'datetime',
    ];

    protected $hidden = [
        'billing_address', // Ocultar endereço por padrão
    ];

    /**
     * Relacionamento com usuário
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope para cartões ativos
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope para cartões não expirados
     */
    public function scopeNotExpired($query)
    {
        $currentYear = (int) date('Y');
        $currentMonth = (int) date('m');

        return $query->where(function ($q) use ($currentYear, $currentMonth) {
            $q->where('exp_year', '>', $currentYear)
              ->orWhere(function ($q2) use ($currentYear, $currentMonth) {
                  $q2->where('exp_year', $currentYear)
                     ->where('exp_month', '>=', $currentMonth);
              });
        });
    }

    /**
     * Scope para cartão padrão
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Retorna número mascarado do cartão
     * Ex: **** **** **** 1234
     */
    public function getMaskedNumberAttribute(): string
    {
        return '**** **** **** ' . ($this->last_four_digits ?? '****');
    }

    /**
     * Retorna data de expiração formatada
     * Ex: 12/2025
     */
    public function getExpirationDateAttribute(): string
    {
        if (!$this->exp_month || !$this->exp_year) {
            return 'N/A';
        }
        
        return str_pad($this->exp_month, 2, '0', STR_PAD_LEFT) . '/' . $this->exp_year;
    }

    /**
     * Verifica se o cartão está expirado
     */
    public function isExpired(): bool
    {
        if (!$this->exp_month || !$this->exp_year) {
            return true;
        }

        $currentYear = (int) date('Y');
        $currentMonth = (int) date('m');

        if ($this->exp_year < $currentYear) {
            return true;
        }

        if ($this->exp_year === $currentYear && $this->exp_month < $currentMonth) {
            return true;
        }

        return false;
    }

    /**
     * Verifica se o cartão está ativo e válido para uso
     */
    public function isValid(): bool
    {
        return $this->status === 'active' && !$this->isExpired();
    }

    /**
     * Define este cartão como padrão do usuário
     */
    public function setAsDefault(): bool
    {
        // Remove flag de padrão dos outros cartões do usuário
        self::where('user_id', $this->user_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        // Define este como padrão
        return $this->update(['is_default' => true]);
    }

    /**
     * Atualiza timestamp de último uso
     */
    public function markAsUsed(): bool
    {
        return $this->update(['last_used_at' => now()]);
    }

    /**
     * Retorna ícone da bandeira (para frontend)
     */
    public function getBrandIconAttribute(): string
    {
        $icons = [
            'visa' => 'fab fa-cc-visa',
            'mastercard' => 'fab fa-cc-mastercard',
            'amex' => 'fab fa-cc-amex',
            'elo' => 'fas fa-credit-card',
            'hipercard' => 'fas fa-credit-card',
            'diners' => 'fab fa-cc-diners-club',
            'discover' => 'fab fa-cc-discover',
            'jcb' => 'fab fa-cc-jcb',
        ];

        $brand = strtolower($this->brand ?? '');
        return $icons[$brand] ?? 'fas fa-credit-card';
    }

    /**
     * Retorna resumo do cartão para exibição
     */
    public function toDisplayArray(): array
    {
        return [
            'id' => $this->id,
            'card_id' => $this->card_id,
            'brand' => $this->brand,
            'brand_icon' => $this->brand_icon,
            'masked_number' => $this->masked_number,
            'holder_name' => $this->holder_name,
            'expiration_date' => $this->expiration_date,
            'is_expired' => $this->isExpired(),
            'is_default' => $this->is_default,
            'label' => $this->label,
            'last_used_at' => $this->last_used_at?->format('d/m/Y H:i'),
        ];
    }
}
