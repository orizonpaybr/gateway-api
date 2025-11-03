<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pixup extends Model
{
    protected $table = "pixup";
    
    protected $fillable = [
        "client_id",
        "client_secret",
        "url",
        "webhook_secret",
        "taxa_adquirente_entradas",
        "taxa_adquirente_saidas",
        "status"
    ];
    
    protected $casts = [
        'status' => 'boolean'
    ];
}