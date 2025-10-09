<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class XDPag extends Model
{
    protected $table = "xdpag";
    
    protected $fillable = [
        "url",
        "client_id",
        "client_secret",
        "webhook_secret",
        "taxa_adquirente_entradas",
        "taxa_adquirente_saidas"
    ];
}
