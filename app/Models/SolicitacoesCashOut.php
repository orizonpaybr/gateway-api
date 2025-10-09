<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolicitacoesCashOut extends Model
{
    protected $table = "solicitacoes_cash_out";

    protected $fillable = [
        "user_id",
        "externalreference",
        "amount",
        "beneficiaryname",
        "beneficiarydocument",
        "pix",
        "pixkey",
        "date",
        "status",
        "type",
        "idTransaction",
        "taxa_cash_out",
        "cash_out_liquido",
        "end_to_end",
        "descricao_transacao",
        "executor_ordem",
      	"descricao_externa",
        "blockchainNetwork",
        "cryptocurrency",
        "callback",
        "primepay7_id"
    ];

    public $casts = [
        "blockchainNetwork" => "array",
        "cryptocurrency" => "array",
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
