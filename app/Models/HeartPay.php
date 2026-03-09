<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HeartPay extends Model
{
    protected $table = 'heartpay';

    protected $fillable = [
        'environment',
        'api_url',
        'api_key',
        'taxa_pix_cash_in',
        'taxa_pix_cash_out',
        'webhook_secret',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
        'taxa_pix_cash_in' => 'decimal:4',
        'taxa_pix_cash_out' => 'decimal:4',
    ];

    protected $hidden = [
        'api_key',
        'webhook_secret',
    ];

    public function isConfigured(): bool
    {
        $apiKey = config('heartpay.api_key');
        return !empty($apiKey);
    }

    public function isActive(): bool
    {
        return $this->status && $this->isConfigured();
    }
}
