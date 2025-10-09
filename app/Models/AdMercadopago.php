<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdMercadopago extends Model
{
    public $table = 'ad_mercadopago';
    protected $fillable = ['access_token'];
}
