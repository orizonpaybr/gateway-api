<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CheckoutOrderBump extends Model
{
    use HasFactory;

    protected $table = 'checkout_order_bumps';

    protected $fillable = [
        'nome',
        'descricao',
        'image',
        'valor_de',
        'valor_por',
        'ativo',
        'checkout_id',
    ];

    public $timestamps = true;

    public function checkout()
    {
        return $this->belongsTo(CheckoutBuild::class, 'checkout_id');
    }
}
