<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pagarme extends Model
{
    use HasFactory;
    
    protected $table = "pagarme";
    
    protected $fillable = [
        // Credenciais
        "token",
        "secret",
        "public_key",
        "webhook_secret",
        
        // URLs
        "url",
        "url_cash_in",
        "url_cash_out",
        
        // Ambiente
        "environment",
        
        // Taxas PIX
        "taxa_pix_cash_in",
        "taxa_pix_cash_out",
        
        // Taxas Cartão
        "card_tx_percent",
        "card_tx_fixed",
        "card_days_availability",
        
        // Flags
        "card_enabled",
        "use_3ds",
    ];

    protected $casts = [
        'taxa_pix_cash_in' => 'decimal:2',
        'taxa_pix_cash_out' => 'decimal:2',
        'card_tx_percent' => 'decimal:2',
        'card_tx_fixed' => 'decimal:2',
        'card_days_availability' => 'integer',
        'card_enabled' => 'boolean',
        'use_3ds' => 'boolean',
    ];

    protected $hidden = [
        'secret',
        'public_key',
        'webhook_secret',
        'token',
    ];

    /**
     * Verifica se pagamentos com cartão estão habilitados
     */
    public function isCardEnabled(): bool
    {
        return $this->card_enabled && !empty($this->secret);
    }

    /**
     * Verifica se 3D Secure está habilitado
     */
    public function is3dsEnabled(): bool
    {
        return $this->use_3ds ?? true;
    }

    /**
     * Retorna URL base da API
     */
    public function getApiUrl(): string
    {
        if ($this->environment === 'production') {
            return $this->url ?? 'https://api.pagar.me/core/v5/';
        }
        
        return 'https://api.pagar.me/core/v5/';
    }
}
