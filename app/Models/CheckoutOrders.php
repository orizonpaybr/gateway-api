<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CheckoutOrders extends Model
{
    use HasFactory;
    
    protected $table = "checkout_orders";

    protected $fillable = [
        "bairro",
        "cep",
        "cidade",
        'estado',
        "complemento",
        "cpf",
        "email",
        "endereco",
        "name",
        "numero",
        "telefone",
        "status",
        "valor_total",
        "checkout_id",
        "quantidade",
        "order_bumps",
        "idTransaction",
        "qrcode",
    ];

    public function checkout()
    {
        return $this->belongsTo(CheckoutBuild::class);
    }
}
