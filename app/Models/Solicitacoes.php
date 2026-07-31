<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Solicitacoes extends Model
{
    use HasFactory;
    
    protected $table = "solicitacoes";

    protected $fillable = [
        "user_id",
        "externalreference",
        "client_reference",
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
        "payer_name",
        "payer_document",
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
        "days_availability",
        "end_to_end",
        "webhook_status",
        "webhook_sent_at",
        "webhook_http_status",
        "webhook_error",
        "webhook_attempts",
        "webhook_request_body"
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * Depósitos que contam como receita confirmada (entradas, gráficos, jornada).
     * Exclui mediação, chargeback, disputa e estorno — o valor ainda não é definitivo do lojista.
     */
    public const CONFIRMED_REVENUE_STATUSES = ['PAID_OUT', 'COMPLETED'];

    public function scopeConfirmedRevenue($query)
    {
        return $query->whereIn('status', self::CONFIRMED_REVENUE_STATUSES);
    }

    /**
     * Filtra depósitos da conta (user_id na tabela costuma ser o username).
     */
    public function scopeForAccount($query, User $user)
    {
        $username = $user->username ?? $user->user_id;

        return $query->where(function ($q) use ($user, $username) {
            $q->where('user_id', $username);
            if (! empty($user->user_id) && $user->user_id !== $username) {
                $q->orWhere('user_id', $user->user_id);
            }
        });
    }
}
