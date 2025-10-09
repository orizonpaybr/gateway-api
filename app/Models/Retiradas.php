<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Retiradas extends Model
{
    protected $table = "retiradas";

    protected $fillable = [
        "user_id",
        "referencia",
        "valor",
        "valor_liquido",
        "tipo_chave",
        "chave",
        "status",
        "data_solicitacao",
        "data_pagamento",
        "taxa_cash_out",
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}