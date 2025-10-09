<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Efi extends Model
{
    public $table = 'efi';

    protected $fillable = [
        'client_id',
        'client_secret',
        'gateway_id',
        'chave_pix',
        'cert',
        'taxa_pix_cash_in',
        'taxa_pix_cash_out',
        'billet_tx_fixed',
        'billet_tx_percent',
        'chave_pix',
        'billet_tx_fixed',
        'billet_tx_percent',
        'identificador_conta',
        'billet_days_availability',
        'card_tx_percent',
        'card_tx_fixed',
        'card_days_availability',
    ];
}
