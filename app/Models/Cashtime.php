<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cashtime extends Model
{
    protected $fillable = [
        "secret",
        "url",
        "url_cash_in",
        "url_cash_out",
        "url_webhook_deposit",
        "url_webhook_payment",
        "taxa_pix_cash_in",
        "taxa_pix_cash_out",
        "taxa_adquirente_entradas",
        "taxa_adquirente_saidas"
    ];
}
