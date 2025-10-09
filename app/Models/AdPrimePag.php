<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdPrimePag extends Model
{
    protected $fillable = [
        'client_id',
        'client_secret',
        'url',
        'url_cash_in',
        'url_cash_out',
        'url_webhook_deposit',
        'url_webhook_payment',
        'taxa_pix_cash_in',
        'taxa_pix_cash_out',
    ];
}