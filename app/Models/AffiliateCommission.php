<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AffiliateCommission extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'affiliate_id',
        'transaction_type',
        'solicitacao_id',
        'solicitacao_cash_out_id',
        'commission_value',
        'transaction_amount',
        'status',
    ];

    protected $casts = [
        'commission_value' => 'decimal:2',
        'transaction_amount' => 'decimal:2',
    ];

    /**
     * Relacionamento: Pai afiliado que recebe a comissão
     */
    public function affiliate()
    {
        return $this->belongsTo(User::class, 'affiliate_id');
    }

    /**
     * Relacionamento: Filho que gerou a transação
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * Relacionamento: Transação de depósito
     */
    public function solicitacao()
    {
        return $this->belongsTo(Solicitacoes::class, 'solicitacao_id');
    }

    /**
     * Relacionamento: Transação de saque
     */
    public function solicitacaoCashOut()
    {
        return $this->belongsTo(SolicitacoesCashOut::class, 'solicitacao_cash_out_id');
    }
}
