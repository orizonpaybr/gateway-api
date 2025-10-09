<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CheckoutDepoimento extends Model
{
    use HasFactory;

    protected $table = 'checkout_depoimentos';

    protected $fillable = [
        'nome',
        'avatar',
        'depoimento',
        'checkout_id',
    ];

    public $timestamps = true;

    public function checkout()
    {
        return $this->belongsTo(CheckoutBuild::class, 'checkout_id');
    }
}
