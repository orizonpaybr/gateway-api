<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asaas extends Model
{
    protected $table = "asaas";
    
    protected $fillable = [
        "api_key",
        "environment", // sandbox ou production
        "webhook_token",
        "url",
    ];
}
