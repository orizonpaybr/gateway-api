<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Woovi extends Model
{
    protected $table = 'woovi';
    
    protected $fillable = [
        'api_key',
        'webhook_secret',
        'url',
        'sandbox',
        'status',
        'taxa_adquirente_entradas',
        'taxa_adquirente_saidas'
    ];

    protected $casts = [
        'sandbox' => 'boolean',
        'status' => 'boolean'
    ];

    public function getApiUrl()
    {
        return $this->sandbox ? 'https://api.woovi-sandbox.com' : 'https://api.woovi.com';
    }
}
