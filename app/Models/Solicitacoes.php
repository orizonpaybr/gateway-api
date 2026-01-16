<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Solicitacoes extends Model
{
    protected $table = "solicitacoes";

    protected $fillable = [
        "user_id",
        "externalreference",
        "amount",
        "client_name",
        "client_document",
        "client_email",
        "date",
        "status",
        "idTransaction",
        "charge_id",
        "primepay7_id",
        "woovi_identifier",
        "deposito_liquido",
        "qrcode_pix",
        "paymentcode",
        "paymentCodeBase64",
        "adquirente_ref",
        "taxa_cash_in",
        "taxa_pix_cash_in_adquirente",
        "taxa_pix_cash_in_valor_fixo",
        "client_telefone",
        "executor_ordem",
        "descricao_transacao",
        "callback",
        "split_email",
        "split_percentage",
        "method",
        "installments",
        "expire_at",
        "billet_download",
        "banking_billet",
        "days_availability"
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
