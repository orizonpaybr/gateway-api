<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transactions extends Model
{

    protected $table = "transactions";

    protected $fillable = [
        "user_id",
        "gerente_id",
        "solicitacao_id",
        "comission_value",
        "transaction_percent",
        "comission_percent",
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function gerente()
    {
        return $this->belongsTo(User::class, 'gerente_id', 'id');
    }

    public function solicitacao()
    {
        return $this->belongsTo(Solicitacoes::class, 'solicitacao_id', 'id');
    }
}
