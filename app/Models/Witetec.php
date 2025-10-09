<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Witetec extends Model
{
    protected $table = "witetec";
    protected $fillable = [
        'url',
        'api_token',
        'tx_billet_fixed',
        'tx_billet_percent',
        'tx_card_fixed',
        'tx_card_percent',
        'taxa_adquirente_entradas',
        'taxa_adquirente_saidas'
    ];
}
