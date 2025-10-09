<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfirmarDeposito extends Model
{
    protected $table = "confirmar_deposito";

    protected $fillable = [
        "email",
        "externalreference",
        "valor",
        "data",
    ];
}