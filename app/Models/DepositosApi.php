<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepositosApi extends Model
{
    protected $table = "depositos_api";

    protected $fillable = [
        "user_id",
        "id_externo",
        "valor",
        "cliente_nome",
        "cliente_documento",
        "cliente_email",
        "cliente_telefone",
        "data_real",
        "status",
        "qrcode",
        "pixcopiaecola",
        "idTransaction",
        "callback_url",
        "adquirente_ref",
        "taxa_cash_in",
        "deposito_liquido",
        "taxa_pix_cash_in_adquirente",
        "taxa_pix_cash_in_valor_fixo",
        "executor_ordem",
        "descricao_transacao",
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}