<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PagArm extends Model
{
    protected $table = "pagarm";
    
    protected $fillable = [
        "client_id",
        "client_secret", 
        "url",
        "webhook_secret",
        "taxa_adquirente_entradas",
        "taxa_adquirente_saidas",
        "status",
        "api_key",
        "environment", // sandbox ou production
        "merchant_id",
        "account_id"
    ];
    
    protected $casts = [
        'status' => 'boolean',
        'taxa_adquirente_entradas' => 'decimal:2',
        'taxa_adquirente_saidas' => 'decimal:2',
    ];
}


